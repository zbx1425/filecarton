<script setup lang="ts">
import { computed, ref, inject, onBeforeUnmount } from 'vue'
import { Checkbox } from '@/components/ui/checkbox'
import { useUiStore } from '@/stores/ui'
import { getFileIcon, getFileIconColor, canPreviewImage } from '@/composables/useFileType'
import { formatSize, formatTime, formatTimeLocalISO8601, formatUnixMode } from '@/utils/format'
import { buildRawUrl } from '@/api/client'
import { joinPath } from '@/utils/path'
import {
  isInternalDrag, handleDrop as dndHandleDrop, startDragFromSelection,
} from '@/composables/useDragDrop'
import { FILE_LIST_KEY, type FileListContext } from './fileListContext'

const props = defineProps<{
  name: string
  isDir: boolean
  size?: number
  mtime: number
  mode?: number
  owner?: string
  group?: string
}>()

const emit = defineEmits<{
  contextmenu: [event: MouseEvent]
}>()

const ctx = inject(FILE_LIST_KEY) as FileListContext
const ui = useUiStore()

const isSelected = computed(() => ctx.isSelected(props.name))
const isCut = computed(() => ctx.isCutItem(props.name))
const icon = computed(() => getFileIcon(props.name, props.isDir))
const iconColor = computed(() => getFileIconColor(props.name, props.isDir))

// ── Click handlers ──

function handleRowClick(e: MouseEvent) {
  if (e.shiftKey) {
    e.preventDefault()
    ctx.rangeSelect(props.name)
  } else if (e.ctrlKey || e.metaKey) {
    ctx.toggleSelect(props.name)
  } else {
    ctx.clearSelection()
    ctx.toggleSelect(props.name)
  }
  ctx.setFocusByName(props.name)
}

function handleDblClick() {
  if (props.isDir) {
    ctx.navigateTo([...ctx.currentPath, props.name])
  } else {
    ctx.openFile(props.name)
  }
}

// ── Drag & drop ──

const dropHover = ref(false)

function handleDragStart(e: DragEvent) {
  if (!ctx.isSelected(props.name)) {
    ctx.clearSelection()
    ctx.toggleSelect(props.name)
  }
  startDragFromSelection(e)
}

function handleDragOver(e: DragEvent) {
  if (!props.isDir) return
  if (!isInternalDrag(e)) return
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'
  dropHover.value = true
}

function handleDragLeave() {
  dropHover.value = false
}

async function handleDrop(e: DragEvent) {
  dropHover.value = false
  if (!props.isDir) return
  await dndHandleDrop(e, joinPath(ctx.currentPathStr, props.name))
}

// ── Thumbnail hover preview ──

const showThumbnail = ref(false)
const thumbnailPos = ref({ x: 0, y: 0 })
let hoverTimer: ReturnType<typeof setTimeout> | null = null
const hasThumbnail = computed(() => !props.isDir && canPreviewImage(props.name, props.size ?? 0))

function handleNameMouseEnter(e: MouseEvent) {
  if (!hasThumbnail.value) return
  hoverTimer = setTimeout(() => {
    thumbnailPos.value = { x: e.clientX + 12, y: e.clientY + 12 }
    showThumbnail.value = true
  }, 300)
}

function handleNameMouseMove(e: MouseEvent) {
  if (showThumbnail.value) {
    thumbnailPos.value = { x: e.clientX + 12, y: e.clientY + 12 }
  }
}

function handleNameMouseLeave() {
  if (hoverTimer) {
    clearTimeout(hoverTimer)
    hoverTimer = null
  }
  showThumbnail.value = false
}

onBeforeUnmount(() => {
  if (hoverTimer) clearTimeout(hoverTimer)
})

const thumbnailUrl = computed(() => {
  if (!hasThumbnail.value) return ''
  return buildRawUrl(joinPath(ctx.currentPathStr, props.name))
})
</script>

<template>
  <div
    data-file-row
    :data-name="name"
    class="flex items-center px-3 text-sm group border-b border-transparent cursor-default select-none h-(--fc-row-height) max-w-(--fc-table-max-w)"
    :class="[
      dropHover ? 'bg-primary/10 border-primary border-dashed' :
      isSelected ? 'bg-selected' : 'hover:bg-muted/50',
      isCut ? 'opacity-50' : '',
    ]"
    :draggable="!ui.readonly"
    @click="handleRowClick"
    @dblclick="handleDblClick"
    @contextmenu="emit('contextmenu', $event)"
    @dragstart="handleDragStart"
    @dragover="handleDragOver"
    @dragleave="handleDragLeave"
    @drop="handleDrop"
  >
    <div class="flex items-center justify-center w-6 shrink-0" @click.stop>
      <Checkbox
        :model-value="isSelected"
        class="size-3.5 opacity-0 group-hover:opacity-100"
        :class="{ '!opacity-100': isSelected }"
        @update:model-value="ctx.toggleSelect(name)"
      />
    </div>

    <div
      class="flex items-center gap-2 flex-1 min-w-0 pr-4"
      @mouseenter="handleNameMouseEnter"
      @mousemove="handleNameMouseMove"
      @mouseleave="handleNameMouseLeave"
    >
      <component :is="icon" class="size-4 shrink-0" :class="iconColor" />
      <span class="truncate" :class="isDir ? 'font-medium' : ''" :title="name">{{ name }}</span>
    </div>

    <div class="w-22 text-muted-foreground shrink-0 tabular-nums">
      {{ isDir ? '—' : formatSize(size ?? 0) }}
    </div>

    <div class="w-28 text-muted-foreground shrink-0" :title="formatTimeLocalISO8601(mtime)">
      {{ formatTime(mtime) }}
    </div>

    <template v-if="ctx.showUnixPerm">
      <div class="w-28 font-mono text-muted-foreground shrink-0">
        {{ mode !== undefined ? formatUnixMode(mode, isDir) : '—' }}
      </div>
      <div
        class="w-36 text-muted-foreground shrink-0 truncate pl-2"
        :title="owner !== undefined && group !== undefined ? `${owner}:${group}` : undefined"
      >
        {{ owner !== undefined && group !== undefined ? `${owner}:${group}` : '—' }}
      </div>
    </template>
  </div>

  <Teleport to="body">
    <div
      v-if="showThumbnail"
      class="fixed z-50 pointer-events-none rounded-md border bg-popover p-1 shadow-md"
      :style="{ left: thumbnailPos.x + 'px', top: thumbnailPos.y + 'px' }"
    >
      <img
        :src="thumbnailUrl"
        :alt="name"
        class="max-w-32 max-h-32 object-contain rounded"
      />
    </div>
  </Teleport>
</template>
