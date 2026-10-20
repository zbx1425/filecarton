<script setup lang="ts">
import { ref, computed, onBeforeUnmount } from 'vue'
import { ArrowUp, ArrowDown, CornerLeftUp, FolderOpen, Eye, Download } from '@lucide/vue'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { buildArchiveRawUrl } from '@/api/client'
import { usePreferencesStore } from '@/stores/preferences'
import { getFileIcon, getFileIconColor, canPreviewImage } from '@/composables/useFileType'
import { formatSize, formatTime, formatTimeLocalISO8601 } from '@/utils/format'
import type { ArchiveTreeItem, ArchiveSortColumn } from '@/composables/useArchiveExplorer'
import FileItemMenu from '@/components/FileItemMenu.vue'

const props = defineProps<{
  archivePath: string
  isRoot: boolean
  dirs: ArchiveTreeItem[]
  files: ArchiveTreeItem[]
  selectedName: string | null
  sortBy: ArchiveSortColumn
  sortAsc: boolean
  isActuallyEmpty: boolean
  isFilteredEmpty: boolean
  searchQuery: string
  randomAccess: boolean
}>()

const emit = defineEmits<{
  'update:selectedName': [name: string | null]
  toggleSort: [column: ArchiveSortColumn]
  goUp: []
  openNode: [node: ArchiveTreeItem]
  downloadNode: [node: ArchiveTreeItem]
}>()

const preferences = usePreferencesStore()

const allRows = computed(() => [...props.dirs, ...props.files])

function sortIcon(column: ArchiveSortColumn) {
  if (props.sortBy !== column) return null
  return props.sortAsc ? ArrowUp : ArrowDown
}

const resizing = ref(false)

function startResize(e: MouseEvent) {
  e.preventDefault()
  resizing.value = true
  const startX = e.clientX
  const startWidth = preferences.effectiveTableMaxWidth ?? e.clientX

  function onMouseMove(ev: MouseEvent) {
    const delta = ev.clientX - startX
    const newWidth = Math.max(400, startWidth + delta)
    if (newWidth > window.innerWidth - 48) {
      preferences.setTableMaxWidth(null)
    } else {
      preferences.setTableMaxWidth(newWidth)
    }
  }

  function onMouseUp() {
    resizing.value = false
    document.removeEventListener('mousemove', onMouseMove)
    document.removeEventListener('mouseup', onMouseUp)
  }

  document.addEventListener('mousemove', onMouseMove)
  document.addEventListener('mouseup', onMouseUp)
}

function handleListBlankClick(e: MouseEvent) {
  const target = e.target as HTMLElement
  if (target.closest('[data-archive-row]')) return
  emit('update:selectedName', null)
}

const hoverThumbNode = ref<ArchiveTreeItem | null>(null)
const thumbnailPos = ref({ x: 0, y: 0 })
let hoverTimer: ReturnType<typeof setTimeout> | null = null

function handleRowNameMouseEnter(e: MouseEvent, node: ArchiveTreeItem) {
  if (node.isDir || !props.randomAccess || !canPreviewImage(node.name, node.size)) return
  if (hoverTimer) clearTimeout(hoverTimer)
  hoverTimer = setTimeout(() => {
    thumbnailPos.value = { x: e.clientX + 12, y: e.clientY + 12 }
    hoverThumbNode.value = node
  }, 300)
}

function handleRowNameMouseMove(e: MouseEvent) {
  if (hoverThumbNode.value) {
    thumbnailPos.value = { x: e.clientX + 12, y: e.clientY + 12 }
  }
}

function handleRowNameMouseLeave() {
  if (hoverTimer) {
    clearTimeout(hoverTimer)
    hoverTimer = null
  }
  hoverThumbNode.value = null
}

onBeforeUnmount(() => {
  if (hoverTimer) clearTimeout(hoverTimer)
})
</script>

<template>
  <div class="flex flex-col h-full">
    <div class="border-b bg-muted/30 sticky top-0 z-10 relative shrink-0" @click="emit('update:selectedName', null)">
      <div
        class="flex items-center px-3 text-sm font-medium text-muted-foreground h-(--fc-row-height) max-w-(--fc-table-max-w)"
      >
        <div
          class="flex items-center gap-1 flex-1 min-w-0 cursor-pointer hover:text-foreground"
          @click="emit('toggleSort', 'name')"
        >
          Name
          <component :is="sortIcon('name')" v-if="sortIcon('name')" class="size-3" />
        </div>
        <div
          class="w-22 cursor-pointer hover:text-foreground flex items-center gap-1"
          @click="emit('toggleSort', 'size')"
        >
          Size
          <component :is="sortIcon('size')" v-if="sortIcon('size')" class="size-3" />
        </div>
        <div
          class="w-28 cursor-pointer hover:text-foreground flex items-center gap-1"
          @click="emit('toggleSort', 'mtime')"
        >
          Modified
          <component :is="sortIcon('mtime')" v-if="sortIcon('mtime')" class="size-3" />
        </div>
      </div>

      <div
        v-if="preferences.effectiveTableMaxWidth != null"
        class="absolute top-0 bottom-0 left-(--fc-table-resize-left) w-1.5 cursor-col-resize transition-colors -translate-x-1/2 group/handle z-20"
        :class="resizing ? 'bg-primary/40' : 'hover:bg-primary/30'"
        @mousedown="startResize"
      >
        <div class="absolute inset-y-0 left-1/2 w-px bg-border group-hover/handle:bg-primary/50" :class="resizing ? 'bg-primary/60' : ''" />
      </div>
    </div>

    <div class="flex-1 overflow-auto relative" @click="handleListBlankClick">
      <div
        v-if="!isRoot"
        data-archive-row
        class="flex items-center px-3 text-sm text-muted-foreground cursor-pointer hover:bg-muted/50 h-(--fc-row-height) max-w-(--fc-table-max-w)"
        @click="emit('goUp')"
      >
        <div class="flex items-center gap-2 flex-1">
          <CornerLeftUp class="size-4" />
          <span>..</span>
        </div>
        <div class="w-22" />
        <div class="w-28" />
      </div>

      <ContextMenu
        v-for="item in allRows"
        :key="item.path"
        @update:open="(open: boolean) => { if (open) emit('update:selectedName', item.name) }"
      >
        <ContextMenuTrigger as-child>
          <div
            data-archive-row
            class="flex items-center px-3 text-sm group border-b border-transparent cursor-default select-none h-(--fc-row-height) max-w-(--fc-table-max-w)"
            :class="selectedName === item.name ? 'bg-selected' : 'hover:bg-muted/50'"
            @click="emit('update:selectedName', item.name)"
            @dblclick="emit('openNode', item)"
          >
            <div
              class="flex items-center gap-2 flex-1 min-w-0 pr-4"
              @mouseenter="handleRowNameMouseEnter($event, item)"
              @mousemove="handleRowNameMouseMove"
              @mouseleave="handleRowNameMouseLeave"
            >
              <component
                :is="getFileIcon(item.name, item.isDir)"
                class="size-4 shrink-0"
                :class="getFileIconColor(item.name, item.isDir)"
              />
              <span class="truncate" :class="item.isDir ? 'font-medium' : ''">{{ item.name }}</span>
            </div>

            <div class="w-22 text-muted-foreground shrink-0 tabular-nums">
              {{ item.isDir ? '—' : formatSize(item.size) }}
            </div>

            <div class="w-28 text-muted-foreground shrink-0" :title="item.mtime ? formatTimeLocalISO8601(item.mtime) : '-'">
              {{ item.mtime ? formatTime(item.mtime) : '—' }}
            </div>
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

      <div v-if="isFilteredEmpty" class="flex flex-col items-center justify-center py-16 text-muted-foreground">
        <p v-if="searchQuery.trim()" class="text-sm">No matches for "{{ searchQuery }}"</p>
        <p v-else class="text-sm">All files under this directory are hidden files</p>
      </div>
      <div v-else-if="isActuallyEmpty" class="flex flex-col items-center justify-center py-16 text-muted-foreground">
        <p class="text-sm">This folder is empty</p>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="hoverThumbNode && randomAccess"
        class="fixed z-50 pointer-events-none rounded-md border bg-popover p-1 shadow-md"
        :style="{ left: thumbnailPos.x + 'px', top: thumbnailPos.y + 'px' }"
      >
        <img
          :src="buildArchiveRawUrl(archivePath, hoverThumbNode.path)"
          :alt="hoverThumbNode.name"
          class="max-w-32 max-h-32 object-contain rounded"
        />
      </div>
    </Teleport>
  </div>
</template>
