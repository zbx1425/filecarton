interface FileCartonConfig {
  apiBase: string
  csrfToken: string
  readonly: boolean
  repoName: string
  branding?: string
}

declare global {
  interface Window {
    __FILECARTON__: FileCartonConfig
  }

  declare const __APP_VERSION__: string
}

export {}
