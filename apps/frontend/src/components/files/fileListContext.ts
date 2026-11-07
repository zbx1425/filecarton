import type { InjectionKey, Reactive } from 'vue'
import type { useFileListView } from '@/composables/useFileListView'

/**
 * An item targeted by a right-click in the file list.
 */
export interface ContextTargetItem {
  name: string
  isDir: boolean
  size?: number
  mode?: number
  owner?: string
  group?: string
}

/**
 * Context provided by FileTable to child components (FileRow, FileListContextMenu)
 * via provide/inject.
 */
export interface FileListContext {
  toggleSelect: (name: string) => void
  rangeSelect: (name: string) => void
  clearSelection: () => void
  setFocusByName: (name: string) => void
  navigateTo: (path: string[]) => void
  openFile: (fileName: string) => void
  isSelected: (name: string) => boolean
  isCutItem: (name: string) => boolean
  currentPath: readonly string[]
  currentPathStr: string
  selectedCount: number
  canPaste: boolean
  showUnixPerm: boolean
  isAllSelected: boolean
  toggleSelectAll: () => void
}

export const FILE_LIST_KEY: InjectionKey<FileListContext> = Symbol('fileListContext')

/**
 * The one reactive file-list model owned by AppShell.
 * Inject the object whole. Do not wrap it in reactive() again, and do not destructure it.
 */
export type FileListViewModel = Reactive<ReturnType<typeof useFileListView>>

export const FILE_LIST_VIEW_KEY: InjectionKey<FileListViewModel> = Symbol('fileListView')
