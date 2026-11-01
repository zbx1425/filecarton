import type { FileCartonConfig } from './api/types'

declare global {
  interface Window {
    __FILECARTON__: FileCartonConfig
  }

  const __APP_VERSION__: string
}

declare module 'markdown-it-task-lists' {
  import type MarkdownIt from 'markdown-it'
  interface TaskListsOptions {
    enabled?: boolean
    label?: boolean
    labelAfter?: boolean
  }
  const taskLists: MarkdownIt.PluginWithOptions<TaskListsOptions>
  export default taskLists
}

export {}
