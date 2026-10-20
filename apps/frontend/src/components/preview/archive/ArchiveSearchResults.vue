<script setup lang="ts">
import { Search, FolderOpen, Eye, Download } from '@lucide/vue'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { getFileIcon, getFileIconColor } from '@/composables/useFileType'
import { formatSize } from '@/utils/format'
import { parentPath, splitPath } from '@/utils/path'
import type { ArchiveTreeItem } from '@/composables/useArchiveExplorer'

defineProps<{
  currentPathStr: string
  searchQuery: string
  results: ArchiveTreeItem[]
  randomAccess: boolean
}>()

const emit = defineEmits<{
  openNode: [node: ArchiveTreeItem]
  navigateDir: [segments: string[]]
  downloadNode: [node: ArchiveTreeItem]
}>()

function handleDirClick(pathStr: string) {
  const parent = parentPath(pathStr)
  emit('navigateDir', parent ? splitPath(parent) : [])
}
</script>

<template>
  <div class="flex flex-col h-full">
    <div class="flex items-center px-3 h-8 text-xs text-muted-foreground border-b bg-muted/30 shrink-0">
      <Search class="size-3.5 mr-2" />
      Searching in /{{ currentPathStr || '(archive root)' }} for "{{ searchQuery }}"
    </div>

    <div v-if="results.length === 0" class="flex-1 flex items-center justify-center text-sm text-muted-foreground">
      No results found
    </div>

    <div v-else class="flex-1 overflow-auto">
      <ContextMenu v-for="item in results" :key="item.path">
        <ContextMenuTrigger as-child>
          <div
            class="flex items-center px-3 text-xs cursor-pointer hover:bg-muted/50 gap-2 h-(--fc-row-height)"
            @click="emit('openNode', item)"
          >
            <component
              :is="getFileIcon(item.name, item.isDir)"
              class="size-4 shrink-0"
              :class="getFileIconColor(item.name, item.isDir)"
            />
            <span class="truncate font-medium">{{ item.name }}</span>
            <span
              class="text-muted-foreground truncate cursor-pointer hover:underline"
              @click.stop="handleDirClick(item.path)"
            >
              /{{ parentPath(item.path) || '(root)' }}
            </span>
            <span v-if="!item.isDir" class="text-muted-foreground shrink-0 tabular-nums ml-auto">
              {{ formatSize(item.size) }}
            </span>
          </div>
        </ContextMenuTrigger>
        <ContextMenuContent class="w-48">
          <ContextMenuItem @select="emit('openNode', item)">
            <FolderOpen v-if="item.isDir" class="size-4" />
            <Eye v-else class="size-4" />
            Open
          </ContextMenuItem>
          <template v-if="!item.isDir && randomAccess">
            <ContextMenuSeparator />
            <ContextMenuItem @select="emit('downloadNode', item)">
              <Download class="size-4" />
              Download
            </ContextMenuItem>
          </template>
        </ContextMenuContent>
      </ContextMenu>
    </div>
  </div>
</template>
