import type { InjectionKey } from 'vue'

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
 * Session-wide file list. Menus inject this instead of FILE_LIST_KEY so they
 * can render outside FileTable. The object must stay intact; do not destructure it.
 */
export interface FileListViewContext {
  selectedCount: number
  canPaste: boolean
  isAllSelected: boolean
  currentPath: readonly string[]
  currentPathStr: string
  isSelected: (name: string) => boolean
  navigateTo: (path: string[]) => unknown
  openFile: (fileName: string) => unknown
  toggleSelectAll: () => void
}

export const FILE_LIST_VIEW_KEY: InjectionKey<FileListViewContext> = Symbol('fileListView')
