import type { InjectionKey } from 'vue'

/**
 * An item targeted by a right-click in the tree panel.
 */
export interface TreeContextTarget {
  name: string
  path: string
  isDir: boolean
}

/**
 * Context provided by TreePanel to TreeNode components via provide/inject.
 * Used for forwarding right-click targets from recursive tree nodes to the single
 * ContextMenu instance at the panel level.
 */
export interface TreePanelContext {
  setContextTarget: (target: TreeContextTarget | null) => void
}

export const TREE_PANEL_KEY: InjectionKey<TreePanelContext> = Symbol('treePanelContext')
