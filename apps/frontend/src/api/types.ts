export interface DirEntry {
  name: string
  mtime: number
}

export interface FileEntry {
  name: string
  size: number
  mtime: number
}

export interface ListResponse {
  dirs: DirEntry[]
  files: FileEntry[]
}

export interface TreeChild {
  name: string
  type: 'dir' | 'file'
  hasChildren: boolean
}

export interface TreeNodeResponse {
  children: TreeChild[]
}

export interface ReadResponse {
  content: string
  size: number
  mtime: number
  mime: string
  encoding: string
}

export interface SearchResult {
  name: string
  path: string
  type: 'dir' | 'file'
  size?: number
}

export interface SearchResponse {
  results: SearchResult[]
  truncated: boolean
  scanLimitReached?: boolean
}

export interface ArchiveEntry {
  path: string
  size: number
  isDir: boolean
}

export interface ArchiveListResponse {
  entries: ArchiveEntry[]
  totalFiles: number
  totalSize: number
  compressedSize: number
}

export interface CreateResponse {
  created: string
}

export interface DeleteResponse {
  deleted: number
  failed: Array<{ name: string; error: string }>
}

export interface RenameResponse {
  renamed: string
}

export interface PasteResponse {
  completed: number
  conflicts: string[]
  failed: Array<{ name: string; error: string }>
  renamed: Array<{ original: string; newName: string }>
}

export interface WriteResponse {
  size: number
  mtime: number
}

export interface UploadResponse {
  uploaded: Array<{ name: string; size: number }>
  failed: Array<{ name: string; error: string }>
}

export interface UploadChunkResponse {
  received: number
}

export interface UploadCompleteResponse {
  name: string
  size: number
}

export interface ArchiveCreateResponse {
  archivePath: string
  size: number
}

export interface ArchiveExtractFailedEntry {
  path: string
  reason: 'blocked_extension' | 'blocked_dotfile' | 'blocked_ignored' | 'io_error'
}

export interface ArchiveExtractResponse {
  extracted: number
  failed: ArchiveExtractFailedEntry[]
  targetPath: string
}

export interface ArchiveExtractDryRunResponse {
  wouldExtract: number
  conflicts: string[]
  failed: ArchiveExtractFailedEntry[]
  targetPath: string
}

export interface CheckUploadConflictsResponse {
  existing: string[]
}
