import type { InjectionKey } from 'vue'
import type { ArchiveTreeItem } from '@/composables/useArchiveExplorer'

export const ARCHIVE_TREE_MENU_KEY: InjectionKey<(node: ArchiveTreeItem) => void> = Symbol('archiveTreeMenu')
