<script setup lang="ts">
import { computed, ref, onBeforeUnmount } from 'vue'
import { Checkbox } from '@/components/ui/checkbox'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { useFileListStore } from '@/stores/fileList'
import { useNavigationStore } from '@/stores/navigation'
import { useClipboardStore } from '@/stores/clipboard'
import { useUiStore } from '@/stores/ui'
import { getFileIcon, getFileIconColor } from '@/composables/useFileType'
import { formatSize, formatTime } from '@/utils/format'
import {
  copySelected, cutSelected, deleteItems, renameItem, pasteItems,
} from '@/composables/useFileActions'
import { buildDownloadUrl, buildRawUrl } from '@/api/client'
import { joinPath } from '@/utils/path'
import { PackageOpen, FileArchive } from '@lucide/vue'
import {
  ContextMenuSub,
  ContextMenuSubTrigger,
  ContextMenuSubContent,
} from '@/components/ui/context-menu'
import FileItemMenu from '@/components/FileItemMenu.vue'
import { isArchive, canPreviewImage } from '@/composables/useFileType'
import { extractArchive, createArchive } from '@/composables/useArchive'
import {
  isInternalDrag, handleDrop as dndHandleDrop, startDragFromSelection,
} from '@/composables/useDragDrop'
import { usePreferencesStore } from '@/stores/preferences'

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
const preferences = usePreferencesStore()

const constrainedStyle = computed(() => {
  const maxW = preferences.effectiveTableMaxWidth
  if (maxW == null) return {}
  return { maxWidth: `${maxW}px` }
})

const isSelected = computed(() => fileList.selected.has(props.name))
const isCut = computed(() => clipboard.isCutItem(navigation.currentPathStr, props.name))
const icon = computed(() => getFileIcon(props.name, props.isDir))
const iconColor = computed(() => getFileIconColor(props.name, props.isDir))

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
  fileList.setFocusByName(props.name)
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
  await renameItem(navigation.currentPathStr, props.name, props.isDir)
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
  return buildRawUrl(joinPath(navigation.currentPathStr, props.name))
})
</script>

<template>
  <ContextMenu @update:open="(open: boolean) => { if (open) handleContextOpen() }">
    <ContextMenuTrigger as-child>
      <div
        data-file-row
        class="flex items-center px-3 text-xs group border-b border-transparent cursor-default select-none"
        style="height: var(--fc-row-height, 32px)"
        :style="constrainedStyle"
        :class="[
          dropHover ? 'bg-primary/10 border-primary border-dashed' :
          isSelected ? 'bg-selected' : 'hover:bg-muted/50',
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
          <component :is="icon" class="size-4 shrink-0" :class="iconColor" />
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
      <FileItemMenu
        :is-dir="isDir"
        :readonly="ui.readonly"
        :can-paste="clipboard.hasContent"
        :show-open="fileList.selectedCount <= 1"
        :show-rename="fileList.selectedCount <= 1"
        :show-download="fileList.selectedCount <= 1"
        :has-hotkey="true"
        @open="handleOpen"
        @copy="handleCopy"
        @cut="handleCut"
        @paste="handlePaste"
        @rename="handleRename"
        @delete="handleDelete"
        @download="handleDownload"
      >
        <template v-if="!ui.readonly">
          <ContextMenuSeparator />
          <template v-if="fileList.selectedCount <= 1 && !isDir && isArchive(name)">
            <ContextMenuItem @select="extractArchive(joinPath(navigation.currentPathStr, name), navigation.currentPathStr)">
              <PackageOpen class="size-4" />
              Extract Here
            </ContextMenuItem>
          </template>
          <template v-else>
            <ContextMenuSub>
              <ContextMenuSubTrigger class="gap-2">
                <FileArchive class="size-4" />
                Archive
              </ContextMenuSubTrigger>
              <ContextMenuSubContent>
                <ContextMenuItem @select="createArchive('zip')">Create .zip</ContextMenuItem>
                <ContextMenuItem @select="createArchive('tar')">Create .tar</ContextMenuItem>
              </ContextMenuSubContent>
            </ContextMenuSub>
          </template>
        </template>
      </FileItemMenu>
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
