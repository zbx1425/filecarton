<script setup lang="ts">
import { provide, ref } from 'vue'
import { ScrollArea } from '@/components/ui/scroll-area'
import { ContextMenu, ContextMenuContent, ContextMenuTrigger } from '@/components/ui/context-menu'
import type { ArchiveTreeItem } from '@/composables/useArchiveExplorer'
import ArchiveEntryMenuBody from './ArchiveEntryMenuBody.vue'
import ArchiveTreeNode from './ArchiveTreeNode.vue'
import { ARCHIVE_TREE_MENU_KEY } from './archiveTreeContext'

defineProps<{
  activePath: string
  activeFilePath: string | null
  expandedPaths: Set<string>
  getChildren: (path: string) => ArchiveTreeItem[]
  randomAccess: boolean
}>()

const emit = defineEmits<{
  toggleExpand: [path: string]
  selectNode: [node: ArchiveTreeItem]
  downloadNode: [node: ArchiveTreeItem]
}>()

const menuNode = ref<ArchiveTreeItem | null>(null)
provide(ARCHIVE_TREE_MENU_KEY, (node) => { menuNode.value = node })

function onAreaContextMenu(e: MouseEvent) {
  if (!(e.target as HTMLElement).closest('[data-archive-tree-node]')) e.preventDefault()
}
</script>

<template>
  <ContextMenu>
    <ContextMenuTrigger as-child>
  <ScrollArea class="h-full" @contextmenu="onAreaContextMenu">
    <div class="py-1">
      <ArchiveTreeNode
        v-for="child in getChildren('')"
        :key="child.path"
        :node="child"
        :depth="0"
        :active-path="activePath"
        :active-file-path="activeFilePath"
        :expanded-paths="expandedPaths"
        :get-children="getChildren"
        :random-access="randomAccess"
        @toggle-expand="emit('toggleExpand', $event)"
        @select-node="emit('selectNode', $event)"
        @download-node="emit('downloadNode', $event)"
      />
    </div>
  </ScrollArea>
    </ContextMenuTrigger>
    <ContextMenuContent v-if="menuNode" class="w-48">
      <ArchiveEntryMenuBody
        surface="context"
        :show-shortcuts="false"
        :flatten-subs="false"
        :is-dir="menuNode.isDir"
        :random-access="randomAccess"
        @open="emit('selectNode', menuNode)"
        @download="emit('downloadNode', menuNode)"
      />
    </ContextMenuContent>
  </ContextMenu>
</template>
