import type { FileCartonConfig } from './api/types'

declare global {
  interface Window {
    __FILECARTON__: FileCartonConfig
  }

  const __APP_VERSION__: string
}

export {}
