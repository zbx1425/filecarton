<?php

namespace FileCarton;

class FileOps {

    /**
     * List directory contents, split into dirs and files, naturally sorted.
     */
    public function listDir(string $absPath): array {
        $entries = scandir($absPath);
        if ($entries === false) {
            throw new \RuntimeException('Cannot read directory', 500);
        }

        $dirs = [];
        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $absPath . '/' . $entry;
            if (is_dir($full)) {
                $dirs[] = ['name' => $entry, 'mtime' => filemtime($full)];
            } elseif (is_file($full)) {
                $files[] = ['name' => $entry, 'size' => filesize($full), 'mtime' => filemtime($full)];
            }
        }

        $this->naturalSort($dirs);
        $this->naturalSort($files);

        return ['dirs' => $dirs, 'files' => $files];
    }

    /**
     * List children for a tree node (lazy-load).
     * Returns dirs first, then files, each naturally sorted.
     */
    public function treeChildren(string $absPath): array {
        $entries = scandir($absPath);
        if ($entries === false) {
            throw new \RuntimeException('Cannot read directory', 500);
        }

        $children = [];
        $dirItems = [];
        $fileItems = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $absPath . '/' . $entry;
            if (is_dir($full)) {
                $hasChildren = false;
                $sub = @scandir($full);
                if ($sub !== false) {
                    $hasChildren = count(array_diff($sub, ['.', '..'])) > 0;
                }
                $dirItems[] = ['name' => $entry, 'type' => 'dir', 'hasChildren' => $hasChildren];
            } elseif (is_file($full)) {
                $fileItems[] = ['name' => $entry, 'type' => 'file', 'hasChildren' => false];
            }
        }

        usort($dirItems, function($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        usort($fileItems, function($a, $b) { return strnatcasecmp($a['name'], $b['name']); });

        return array_merge($dirItems, $fileItems);
    }

    public function copyItem(string $src, string $dst): void {
        if (is_file($src)) {
            $dstDir = dirname($dst);
            if (!is_dir($dstDir)) {
                mkdir($dstDir, 0755, true);
            }
            if (!copy($src, $dst)) {
                throw new \RuntimeException('Failed to copy file: ' . basename($src));
            }
        } elseif (is_dir($src)) {
            if (!Platform::copyRecursive($src, $dst)) {
                throw new \RuntimeException('Failed to copy directory: ' . basename($src));
            }
        } else {
            throw new \RuntimeException('Source does not exist: ' . basename($src));
        }
    }

    public function moveItem(string $src, string $dst): void {
        $dstDir = dirname($dst);
        if (!is_dir($dstDir)) {
            mkdir($dstDir, 0755, true);
        }
        if (!rename($src, $dst)) {
            throw new \RuntimeException('Failed to move: ' . basename($src));
        }
    }

    /**
     * Merge-move a directory into an existing destination directory.
     * Moves files one by one (overwriting existing), creates subdirs as needed,
     * then removes the now-empty source directory tree.
     */
    public function mergeMove(string $src, string $dst): void {
        $src = rtrim(str_replace('\\', '/', $src), '/');
        $dst = rtrim(str_replace('\\', '/', $dst), '/');

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $srcLen = strlen($src) + 1;

        foreach ($iter as $item) {
            $itemPath = str_replace('\\', '/', $item->getPathname());
            $relativePath = substr($itemPath, $srcLen);
            $targetPath = $dst . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    if (!mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                        throw new \RuntimeException('Failed to create directory during merge: ' . $relativePath);
                    }
                }
            } else {
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                if (file_exists($targetPath)) {
                    @unlink($targetPath);
                }
                if (!rename($itemPath, $targetPath)) {
                    throw new \RuntimeException('Failed to move file during merge: ' . $relativePath);
                }
            }
        }

        // Clean up source directory (should be empty now)
        Platform::deleteRecursive($src);
    }

    public function deleteItem(string $absPath): void {
        if (!file_exists($absPath) && !is_link($absPath)) {
            return;
        }
        if (!Platform::deleteRecursive($absPath)) {
            throw new \RuntimeException('Failed to delete: ' . basename($absPath));
        }
    }

    public function createDir(string $absPath): void {
        if (!mkdir($absPath, 0755, true) && !is_dir($absPath)) {
            throw new \RuntimeException('Failed to create directory');
        }
    }

    public function createFile(string $absPath): void {
        if (file_put_contents($absPath, '') === false) {
            throw new \RuntimeException('Failed to create file');
        }
    }

    public function readFile(string $absPath): string {
        $content = file_get_contents($absPath);
        if ($content === false) {
            throw new \RuntimeException('Failed to read file');
        }
        return $content;
    }

    public function writeFile(string $absPath, string $content): int {
        $bytes = file_put_contents($absPath, $content);
        if ($bytes === false) {
            throw new \RuntimeException('Failed to write file');
        }
        return $bytes;
    }

    /**
     * Sort an array of items by 'name' using natural case-insensitive ordering.
     */
    public function naturalSort(array &$items): void {
        usort($items, function($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
    }
}
