<script setup lang="ts">
import { computed } from 'vue'
import { ChevronRight, FolderOpen, Eye, Download } from '@lucide/vue'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { getFileIcon, getFileIconColor } from '@/composables/useFileType'
import type { ArchiveTreeItem } from '@/composables/useArchiveExplorer'

const props = defineProps<{
  node: ArchiveTreeItem
  depth: number
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

const isExpanded = computed(() => props.node.isDir && props.expandedPaths.has(props.node.path))
const children = computed(() => (props.node.isDir ? props.getChildren(props.node.path) : []))
const hasVisibleChildren = computed(() => props.node.isDir && children.value.length > 0)

const isActive = computed(() => {
  if (props.node.isDir) {
    return props.activeFilePath === null && props.activePath === props.node.path
  }
  return props.activeFilePath === props.node.path
})

const icon = computed(() => getFileIcon(props.node.name, props.node.isDir, isExpanded.value))
const iconColor = computed(() => getFileIconColor(props.node.name, props.node.isDir, isExpanded.value))

function handleChevronClick(e: Event) {
  e.stopPropagation()
  if (props.node.isDir) {
    emit('toggleExpand', props.node.path)
  }
}

function handleClick() {
  emit('selectNode', props.node)
}
</script>

<template>
  <div>
    <ContextMenu>
      <ContextMenuTrigger as-child>
        <div
          class="flex items-center h-7 pl-1 cursor-pointer select-none group"
          :class="isActive ? 'bg-accent text-accent-foreground font-medium' : 'hover:bg-muted/50'"
          @click="handleClick"
        >
          <span v-for="i in depth" :key="i" class="w-4 shrink-0" />
          <span
            v-if="node.isDir"
            class="flex items-center justify-center w-4 h-4 shrink-0"
            @click.stop="handleChevronClick"
          >
            <ChevronRight
              v-if="hasVisibleChildren"
              class="size-3 text-muted-foreground transition-transform duration-150"
              :class="{ 'rotate-90': isExpanded }"
            />
          </span>
          <span v-else class="w-4 shrink-0" />

          <component :is="icon" class="size-4 mx-1.5 shrink-0" :class="iconColor" />
          <span class="truncate text-xs">{{ node.name }}</span>
        </div>
      </ContextMenuTrigger>

      <ContextMenuContent class="w-48">
        <ContextMenuItem @select="handleClick">
          <FolderOpen v-if="node.isDir" class="size-4" />
          <Eye v-else class="size-4" />
          Open
        </ContextMenuItem>
        <template v-if="!node.isDir && randomAccess">
          <ContextMenuSeparator />
          <ContextMenuItem @select="emit('downloadNode', node)">
            <Download class="size-4" />
            Download
          </ContextMenuItem>
        </template>
      </ContextMenuContent>
    </ContextMenu>

    <template v-if="node.isDir && isExpanded">
      <ArchiveTreeNode
        v-for="child in children"
        :key="child.path"
        :node="child"
        :depth="depth + 1"
        :active-path="activePath"
        :active-file-path="activeFilePath"
        :expanded-paths="expandedPaths"
        :get-children="getChildren"
        :random-access="randomAccess"
        @toggle-expand="emit('toggleExpand', $event)"
        @select-node="emit('selectNode', $event)"
        @download-node="emit('downloadNode', $event)"
      />
    </template>
  </div>
</template>
