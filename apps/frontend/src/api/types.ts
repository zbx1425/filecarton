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
  skipped: string[]
}

export interface ArchiveExtractFailedEntry {
  path: string
  reason: 'blocked.extension' | 'blocked.ignored' | 'blocked.invalid' | 'io_error'
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

export interface FileCartonAuthPluginIcon {
  mono_url: string
  light_tint?: string
  dark_tint?: string
}

export interface FileCartonAuthPlugin {
  id: string
  label: string
  kind: 'password' | 'redirect'
  icon?: string | FileCartonAuthPluginIcon
}

export interface FileCartonAuthUser {
  id: string
  displayName: string
  pluginId: string
}

export interface FileCartonAuthConfig {
  enabled: boolean
  implicit?: boolean
  authenticated?: boolean
  user?: FileCartonAuthUser | null
  plugins?: FileCartonAuthPlugin[]
  error?: string
  errorParams?: Record<string, string>
}

export interface FileCartonConfig {
  apiBase: string
  csrfToken: string
  readonly: boolean
  repoName?: string
  branding?: string
  upload?: {
    maxFileSize: number
    chunkSize: number
  }
  dotfiles?: {
    block: boolean
    forceVisible: boolean
  }
  extensions?: {
    allowlist: string[]
    blocklist: string[]
  }
  auth?: FileCartonAuthConfig
}
