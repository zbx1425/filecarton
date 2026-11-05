import { useFileTreeCacheStore } from '@/stores/fileTreeCache'
import { useNavigationStore } from '@/stores/navigation'
import { useTreeUiStore } from '@/stores/treeUi'

export interface MutationRefreshOptions {
  /** Directories whose direct children list changed. fileList: exact-match refresh */
  dirs?: string[]
  /** Directories whose subtree contents changed (path still exists). fileList: prefix-match refresh */
  subtrees?: string[]
  /** Paths that no longer exist (deleted or old name after rename). No fileList refresh */
  removed?: string[]
}

export function handleMutation(opts: MutationRefreshOptions): void {
  const cache = useFileTreeCacheStore()
  const navigation = useNavigationStore()
  const treePanel = useTreeUiStore()

  const currentPath = navigation.currentPathStr
  let shouldRefreshFileList = false

  const processed = new Set<string>()

  // Phase 1: removed — invalidateSubtree + collapse, do NOT loadChildren
  if (opts.removed) {
    for (const path of opts.removed) {
      if (processed.has(path)) continue
      processed.add(path)
      cache.invalidateSubtree(path)
      treePanel.collapseSubtree(path)
      treePanel.setExpanded(path, false)
    }
  }

  // Phase 2: subtrees — invalidateSubtree + reload all expanded descendants
  if (opts.subtrees) {
    for (const path of opts.subtrees) {
      if (processed.has(path)) continue
      processed.add(path)
      cache.invalidateSubtree(path)
      treePanel.collapseSubtree(path)
      // Reload the subtree root if expanded
      if (path === '' || treePanel.isExpanded(path)) {
        cache.fetchTreeChildren(path, { force: true }).catch(() => {})
      }
      // Reload all expanded descendants within this subtree
      const prefix = path ? path + '/' : ''
      for (const expandedPath of treePanel.expanded) {
        if (expandedPath.startsWith(prefix) && expandedPath !== path) {
          cache.fetchTreeChildren(expandedPath, { force: true }).catch(() => {})
        }
      }
      // prefix-match: current view is inside this subtree
      if (currentPath === path || currentPath.startsWith(path ? path + '/' : '')) {
        shouldRefreshFileList = true
      }
    }
  }

  // Phase 3: dirs — invalidate single level + reload if visible
  // fetchDirectory now also updates tree cache, so for the current path
  // we only need the list fetch; for other expanded paths we use tree_node.
  if (opts.dirs) {
    for (const path of opts.dirs) {
      if (processed.has(path)) continue
      processed.add(path)
      cache.invalidateDirectory(path)
      // If this is not the current path but is expanded, refresh tree via tree_node
      if (path !== currentPath && (path === '' || treePanel.isExpanded(path))) {
        cache.fetchTreeChildren(path).catch(() => {})
      }
      // exact-match: file list only stale when viewing this exact directory
      if (currentPath === path) {
        shouldRefreshFileList = true
      }
    }
  }

  if (shouldRefreshFileList) {
    cache.fetchDirectory(currentPath)
  }
}
