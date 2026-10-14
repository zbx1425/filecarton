<script setup lang="ts">
import { ScrollArea } from '@/components/ui/scroll-area'
import type { ArchiveTreeItem } from '@/composables/useArchiveExplorer'
import ArchiveTreeNode from './ArchiveTreeNode.vue'

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
</script>

<template>
  <ScrollArea class="h-full">
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
</template>
