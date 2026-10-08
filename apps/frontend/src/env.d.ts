interface FileCartonAuthPluginIcon {
  mono_url: string
  light_tint?: string
  dark_tint?: string
}

interface FileCartonAuthPlugin {
  id: string
  label: string
  kind: 'password' | 'redirect'
  icon?: string | FileCartonAuthPluginIcon
}

interface FileCartonAuthUser {
  id: string
  displayName: string
  pluginId: string
}

interface FileCartonAuthConfig {
  enabled: boolean
  implicit?: boolean
  authenticated?: boolean
  user?: FileCartonAuthUser | null
  plugins?: FileCartonAuthPlugin[]
  error?: string
}

interface FileCartonConfig {
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

declare global {
  interface Window {
    __FILECARTON__: FileCartonConfig
  }

  declare const __APP_VERSION__: string
}

export {}
