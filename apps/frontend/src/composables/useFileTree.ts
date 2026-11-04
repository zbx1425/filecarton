import { useTreeStore } from '@/stores/tree'
import { useFileListStore } from '@/stores/fileList'
import { useNavigationStore } from '@/stores/navigation'

export interface MutationRefreshOptions {
  /** Directories whose direct children list changed. fileList: exact-match refresh */
  dirs?: string[]
  /** Directories whose subtree contents changed (path still exists). fileList: prefix-match refresh */
  subtrees?: string[]
  /** Paths that no longer exist (deleted or old name after rename). No fileList refresh */
  removed?: string[]
  /** Use background mode for fileList refresh (preserves current data). Used by upload */
  fileListBackground?: boolean
}

export function invalidateFileTree(opts: MutationRefreshOptions): void {
  const tree = useTreeStore()
  const fileList = useFileListStore()
  const navigation = useNavigationStore()

  const currentPath = navigation.currentPathStr
  let shouldRefreshFileList = false

  const processed = new Set<string>()

  // Phase 1: removed — invalidateSubtree + collapse, do NOT loadChildren
  if (opts.removed) {
    for (const path of opts.removed) {
      if (processed.has(path)) continue
      processed.add(path)
      tree.invalidateSubtree(path)
      tree.setExpanded(path, false)
    }
  }

  // Phase 2: subtrees — invalidateSubtree + reload if visible
  if (opts.subtrees) {
    for (const path of opts.subtrees) {
      if (processed.has(path)) continue
      processed.add(path)
      tree.invalidateSubtree(path)
      if (path === '' || tree.isExpanded(path)) {
        tree.loadChildren(path)
      }
      // prefix-match: current view is inside this subtree
      if (currentPath === path || currentPath.startsWith(path ? path + '/' : '')) {
        shouldRefreshFileList = true
      }
    }
  }

  // Phase 3: dirs — invalidate single level + reload if visible
  if (opts.dirs) {
    for (const path of opts.dirs) {
      if (processed.has(path)) continue
      processed.add(path)
      tree.invalidate(path)
      if (path === '' || tree.isExpanded(path)) {
        tree.loadChildren(path)
      }
      // exact-match: file list only stale when viewing this exact directory
      if (currentPath === path) {
        shouldRefreshFileList = true
      }
    }
  }

  if (shouldRefreshFileList) {
    fileList.fetchDir(currentPath, opts.fileListBackground)
  }
}
