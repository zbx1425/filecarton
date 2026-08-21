interface FileCartonConfig {
  apiBase: string
  csrfToken: string
  readonly: boolean
  repoName: string
}

interface Window {
  __FILECARTON__: FileCartonConfig
}
