interface FileCartonConfig {
  apiBase: string
  csrfToken: string
  readonly: boolean
  repoName: string
}

declare global {
  interface Window {
    __FILECARTON__: FileCartonConfig
  }
}

export {}
