<?php

namespace FileCarton;

/**
 * Streaming ZIP writer that outputs directly to php://output (or any writable stream).
 *
 * Uses Data Descriptors (bit 3 of general purpose flags) so that CRC-32 and
 * sizes do not need to be known before writing each entry. Each file is
 * compressed with raw DEFLATE (method 8). Directories are stored uncompressed.
 *
 * Limitations:
 * - No ZIP64 support (FILECARTON_ARCHIVE_MAX_SIZE < 4 GB makes this acceptable).
 * - No encryption.
 * - Does not produce Content-Length (streaming).
 */
class StreamingZipWriter {

    /** @var resource Output stream */
    private $out;

    /** @var int Current byte offset in the output */
    private $offset = 0;

    /** @var array[] Central directory entry records */
    private $centralEntries = [];

    /** ZIP signature constants */
    private const LOCAL_HEADER_SIG     = 0x04034b50;
    private const DATA_DESCRIPTOR_SIG  = 0x08074b50;
    private const CENTRAL_HEADER_SIG   = 0x02014b50;
    private const END_OF_CENTRAL_SIG   = 0x06054b50;

    /** General purpose bit flag: bit 3 = data descriptor follows */
    private const FLAG_DATA_DESCRIPTOR = 0x0008;
    /** General purpose bit flag: bit 11 = UTF-8 filenames */
    private const FLAG_UTF8            = 0x0800;

    private const METHOD_STORED  = 0;
    private const METHOD_DEFLATE = 8;

    /**
     * @param resource $outputStream  Writable stream (typically fopen('php://output', 'wb'))
     */
    public function __construct($outputStream) {
        $this->out = $outputStream;
    }

    /**
     * Add a file entry by streaming its content from disk.
     */
    public function addFileFromPath(string $entryName, string $filePath): void {
        $fp = @fopen($filePath, 'rb');
        if ($fp === false) return;

        $mtime = (int)@filemtime($filePath);
        $dosTime = self::unixToDosTime($mtime);
        $flags = self::FLAG_DATA_DESCRIPTOR | self::FLAG_UTF8;
        $nameBytes = $entryName;
        $nameLen = strlen($nameBytes);
        $localOffset = $this->offset;

        // Local file header (CRC/sizes zeroed — will be in data descriptor)
        $header = pack('V', self::LOCAL_HEADER_SIG)
            . pack('v', 20)               // version needed to extract (2.0)
            . pack('v', $flags)            // general purpose bit flag
            . pack('v', self::METHOD_DEFLATE) // compression method
            . pack('V', $dosTime)          // last mod file time+date
            . pack('V', 0)                 // crc-32 (in data descriptor)
            . pack('V', 0)                 // compressed size (in data descriptor)
            . pack('V', 0)                 // uncompressed size (in data descriptor)
            . pack('v', $nameLen)          // file name length
            . pack('v', 0);               // extra field length

        $this->write($header);
        $this->write($nameBytes);

        // Stream file through deflate + CRC32
        $crcCtx = hash_init('crc32b');
        $deflateCtx = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
        $uncompressedSize = 0;
        $compressedSize = 0;

        $finished = false;
        while (!feof($fp)) {
            $chunk = fread($fp, 65536);
            if ($chunk === false || $chunk === '') break;
            hash_update($crcCtx, $chunk);
            $uncompressedSize += strlen($chunk);

            if (feof($fp)) {
                $compressed = deflate_add($deflateCtx, $chunk, ZLIB_FINISH);
                $finished = true;
            } else {
                $compressed = deflate_add($deflateCtx, $chunk, ZLIB_NO_FLUSH);
            }
            if ($compressed !== false && $compressed !== '') {
                $this->write($compressed);
                $compressedSize += strlen($compressed);
            }
        }
        if (!$finished) {
            $compressed = deflate_add($deflateCtx, '', ZLIB_FINISH);
            if ($compressed !== false && $compressed !== '') {
                $this->write($compressed);
                $compressedSize += strlen($compressed);
            }
        }
        fclose($fp);

        $crc32 = self::hashToCrc32Int(hash_final($crcCtx));

        // Data descriptor
        $descriptor = pack('V', self::DATA_DESCRIPTOR_SIG)
            . pack('V', $crc32)
            . pack('V', $compressedSize)
            . pack('V', $uncompressedSize);
        $this->write($descriptor);

        // Record for central directory
        $this->centralEntries[] = [
            'name'             => $nameBytes,
            'method'           => self::METHOD_DEFLATE,
            'dosTime'          => $dosTime,
            'crc32'            => $crc32,
            'compressedSize'   => $compressedSize,
            'uncompressedSize' => $uncompressedSize,
            'localOffset'      => $localOffset,
            'flags'            => $flags,
            'externalAttr'     => 0,
        ];
    }

    /**
     * Add a directory entry (empty).
     */
    public function addDirectory(string $entryName): void {
        if (!str_ends_with($entryName, '/')) {
            $entryName .= '/';
        }

        $dosTime = self::unixToDosTime(time());
        $flags = self::FLAG_UTF8;
        $nameBytes = $entryName;
        $nameLen = strlen($nameBytes);
        $localOffset = $this->offset;

        $header = pack('V', self::LOCAL_HEADER_SIG)
            . pack('v', 20)
            . pack('v', $flags)
            . pack('v', self::METHOD_STORED)
            . pack('V', $dosTime)
            . pack('V', 0)     // crc
            . pack('V', 0)     // compressed
            . pack('V', 0)     // uncompressed
            . pack('v', $nameLen)
            . pack('v', 0);

        $this->write($header);
        $this->write($nameBytes);

        $this->centralEntries[] = [
            'name'             => $nameBytes,
            'method'           => self::METHOD_STORED,
            'dosTime'          => $dosTime,
            'crc32'            => 0,
            'compressedSize'   => 0,
            'uncompressedSize' => 0,
            'localOffset'      => $localOffset,
            'flags'            => $flags,
            'externalAttr'     => 0x10,  // MS-DOS directory attribute
        ];
    }

    /**
     * Finalize the ZIP archive by writing the Central Directory and EOCD.
     */
    public function finish(): void {
        $cdStart = $this->offset;
        $entryCount = count($this->centralEntries);

        foreach ($this->centralEntries as $entry) {
            $nameLen = strlen($entry['name']);
            $cd = pack('V', self::CENTRAL_HEADER_SIG)
                . pack('v', 20)                    // version made by (2.0, MS-DOS)
                . pack('v', 20)                    // version needed
                . pack('v', $entry['flags'])
                . pack('v', $entry['method'])
                . pack('V', $entry['dosTime'])
                . pack('V', $entry['crc32'])
                . pack('V', $entry['compressedSize'])
                . pack('V', $entry['uncompressedSize'])
                . pack('v', $nameLen)              // file name length
                . pack('v', 0)                     // extra field length
                . pack('v', 0)                     // file comment length
                . pack('v', 0)                     // disk number start
                . pack('v', 0)                     // internal file attributes
                . pack('V', $entry['externalAttr'])
                . pack('V', $entry['localOffset']);

            $this->write($cd);
            $this->write($entry['name']);
        }

        $cdEnd = $this->offset;
        $cdSize = $cdEnd - $cdStart;

        // End of central directory record
        $eocd = pack('V', self::END_OF_CENTRAL_SIG)
            . pack('v', 0)             // number of this disk
            . pack('v', 0)             // disk with central directory
            . pack('v', $entryCount)   // entries on this disk
            . pack('v', $entryCount)   // total entries
            . pack('V', $cdSize)       // size of central directory
            . pack('V', $cdStart)      // offset of start of central directory
            . pack('v', 0);            // ZIP file comment length

        $this->write($eocd);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function write(string $data): void {
        $len = strlen($data);
        if ($len === 0) return;
        $pos = 0;
        while ($pos < $len) {
            $written = @fwrite($this->out, $pos === 0 ? $data : substr($data, $pos));
            if ($written === false || $written === 0) {
                $this->offset += $pos;
                throw new \RuntimeException('fwrite failed during streaming ZIP output');
            }
            $pos += $written;
        }
        $this->offset += $len;
    }

    /**
     * Convert a Unix timestamp to DOS date/time packed as a single 32-bit value.
     */
    private static function unixToDosTime(int $timestamp): int {
        if ($timestamp <= 0) $timestamp = time();
        $d = getdate($timestamp);
        $year = max($d['year'] - 1980, 0);
        $dosDate = ($year << 9) | ($d['mon'] << 5) | $d['mday'];
        $dosTime = ($d['hours'] << 11) | ($d['minutes'] << 5) | (int)($d['seconds'] / 2);
        return ($dosDate << 16) | $dosTime;
    }

    /**
     * Convert a hex CRC-32B hash string to an unsigned 32-bit integer.
     * PHP's hash('crc32b', ...) returns a big-endian hex string.
     */
    private static function hashToCrc32Int(string $hexHash): int {
        return (int)hexdec($hexHash);
    }
}
