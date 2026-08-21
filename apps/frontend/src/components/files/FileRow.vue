<script setup lang="ts">
import { computed, ref, onBeforeUnmount } from 'vue'
import { Checkbox } from '@/components/ui/checkbox'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuShortcut,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { useFileListStore } from '@/stores/fileList'
import { useNavigationStore } from '@/stores/navigation'
import { useClipboardStore } from '@/stores/clipboard'
import { useUiStore } from '@/stores/ui'
import { getFileIcon } from '@/composables/useFileType'
import { formatSize, formatTime } from '@/utils/format'
import {
  copySelected, cutSelected, deleteItems, renameItem, pasteItems,
} from '@/composables/useFileActions'
import { buildDownloadUrl } from '@/api/client'
import { joinPath } from '@/utils/path'
import {
  FolderOpen, Eye, Copy, Scissors, ClipboardPaste,
  Pencil, Trash2, Download, PackageOpen,
} from '@lucide/vue'
import { isArchive, canPreviewImage } from '@/composables/useFileType'
import { extractArchive } from '@/composables/useArchive'
import {
  isInternalDrag, handleDrop as dndHandleDrop, startDragFromSelection,
} from '@/composables/useDragDrop'

const props = defineProps<{
  name: string
  isDir: boolean
  size?: number
  mtime: number
}>()

const fileList = useFileListStore()
const navigation = useNavigationStore()
const clipboard = useClipboardStore()
const ui = useUiStore()

const isSelected = computed(() => fileList.selected.has(props.name))
const isCut = computed(() => clipboard.isCutItem(navigation.currentPathStr, props.name))
const icon = computed(() => getFileIcon(props.name, props.isDir))

function handleRowClick(e: MouseEvent) {
  if (e.shiftKey) {
    e.preventDefault()
    fileList.rangeSelect(props.name)
  } else if (e.ctrlKey || e.metaKey) {
    fileList.toggleSelect(props.name)
  } else {
    fileList.clearSelection()
    fileList.toggleSelect(props.name)
  }
}

function handleDblClick() {
  if (props.isDir) {
    navigation.navigateTo([...navigation.currentPath, props.name])
  } else {
    navigation.openFile(props.name)
  }
}

function handleContextOpen() {
  if (!fileList.selected.has(props.name)) {
    fileList.clearSelection()
    fileList.toggleSelect(props.name)
  }
}

function handleOpen() {
  if (props.isDir) {
    navigation.navigateTo([...navigation.currentPath, props.name])
  } else {
    navigation.openFile(props.name)
  }
}

async function handleCopy() {
  fileList.selected.has(props.name) || fileList.toggleSelect(props.name)
  copySelected()
}

async function handleCut() {
  fileList.selected.has(props.name) || fileList.toggleSelect(props.name)
  cutSelected()
}

async function handlePaste() {
  if (props.isDir) {
    await pasteItems(joinPath(navigation.currentPathStr, props.name))
  } else {
    await pasteItems(navigation.currentPathStr)
  }
}

async function handleRename() {
  await renameItem(navigation.currentPathStr, props.name)
}

async function handleDelete() {
  const items = fileList.selected.has(props.name)
    ? Array.from(fileList.selected)
    : [props.name]
  await deleteItems(navigation.currentPathStr, items)
}

function handleDownload() {
  const filePath = joinPath(navigation.currentPathStr, props.name)
  window.open(buildDownloadUrl(filePath), '_blank')
}

const dropHover = ref(false)

function handleDragStart(e: DragEvent) {
  if (!fileList.selected.has(props.name)) {
    fileList.clearSelection()
    fileList.toggleSelect(props.name)
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
  await dndHandleDrop(e, joinPath(navigation.currentPathStr, props.name))
}

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
  return buildDownloadUrl(joinPath(navigation.currentPathStr, props.name))
})
</script>

<template>
  <ContextMenu @update:open="(open: boolean) => { if (open) handleContextOpen() }">
    <ContextMenuTrigger as-child>
      <div
        class="flex items-center h-8 px-3 text-xs group border-b border-transparent cursor-default select-none"
        :class="[
          dropHover ? 'bg-primary/10 border-primary border-dashed' :
          isSelected ? 'bg-accent' : 'hover:bg-muted/50',
          isCut ? 'opacity-50' : '',
        ]"
        :draggable="true"
        @click="handleRowClick"
        @dblclick="handleDblClick"
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
            @update:model-value="fileList.toggleSelect(name)"
          />
        </div>

        <div
          class="flex items-center gap-2 flex-1 min-w-0 pr-4"
          @mouseenter="handleNameMouseEnter"
          @mousemove="handleNameMouseMove"
          @mouseleave="handleNameMouseLeave"
        >
          <component :is="icon" class="size-4 shrink-0 text-muted-foreground" />
          <span class="truncate" :class="isDir ? 'font-medium' : ''">{{ name }}</span>
        </div>

        <div class="w-20 text-right text-muted-foreground shrink-0 tabular-nums">
          {{ isDir ? '—' : formatSize(size ?? 0) }}
        </div>

        <div class="w-24 text-right text-muted-foreground shrink-0">
          {{ formatTime(mtime) }}
        </div>
      </div>
    </ContextMenuTrigger>

    <ContextMenuContent class="w-52">
      <ContextMenuItem @select="handleOpen">
        <FolderOpen v-if="isDir" class="size-4" />
        <Eye v-else class="size-4" />
        Open
      </ContextMenuItem>

      <ContextMenuSeparator />

      <template v-if="!ui.readonly">
        <ContextMenuItem @select="handleCopy">
          <Copy class="size-4" />
          Copy
          <ContextMenuShortcut>Ctrl+C</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuItem @select="handleCut">
          <Scissors class="size-4" />
          Cut
          <ContextMenuShortcut>Ctrl+X</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuItem v-if="clipboard.hasContent" @select="handlePaste">
          <ClipboardPaste class="size-4" />
          Paste
          <ContextMenuShortcut>Ctrl+V</ContextMenuShortcut>
        </ContextMenuItem>

        <ContextMenuSeparator />

        <ContextMenuItem @select="handleRename">
          <Pencil class="size-4" />
          Rename
          <ContextMenuShortcut>F2</ContextMenuShortcut>
        </ContextMenuItem>
        <ContextMenuItem class="text-destructive focus:text-destructive" @select="handleDelete">
          <Trash2 class="size-4" />
          Delete
          <ContextMenuShortcut>Del</ContextMenuShortcut>
        </ContextMenuItem>

        <ContextMenuSeparator />
      </template>

      <ContextMenuItem @select="handleDownload">
        <Download class="size-4" />
        Download
      </ContextMenuItem>

      <template v-if="!ui.readonly && !isDir && isArchive(name)">
        <ContextMenuSeparator />
        <ContextMenuItem @select="extractArchive(joinPath(navigation.currentPathStr, name), navigation.currentPathStr)">
          <PackageOpen class="size-4" />
          Extract Here
        </ContextMenuItem>
      </template>
    </ContextMenuContent>
  </ContextMenu>

  <Teleport to="body">
    <div
      v-if="showThumbnail"
      class="fixed z-50 pointer-events-none rounded-md border bg-popover p-1 shadow-md"
      :style="{ left: thumbnailPos.x + 'px', top: thumbnailPos.y + 'px' }"
    >
      <img
        :src="thumbnailUrl"
        :alt="name"
        class="max-w-[128px] max-h-[128px] object-contain rounded"
      />
    </div>
  </Teleport>
</template>
