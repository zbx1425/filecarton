<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowUp, ArrowDown, CornerLeftUp, Loader2, ClipboardPaste, File, Folder, CheckSquare, Square } from '@lucide/vue'
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
import { usePreferencesStore } from '@/stores/preferences'
import { formatSize } from '@/utils/format'
import { createItem, pasteItems } from '@/composables/useFileActions'
import FileRow from './FileRow.vue'
import BatchActionBar from './BatchActionBar.vue'

const fileList = useFileListStore()
const navigation = useNavigationStore()
const clipboard = useClipboardStore()
const ui = useUiStore()
const preferences = usePreferencesStore()

const isRoot = computed(() => navigation.currentPath.length === 0)

const isActuallyEmpty = computed(
  () => fileList.dirs.length === 0 && fileList.files.length === 0 && !fileList.loading,
)

const isFilteredEmpty = computed(
  () => !isActuallyEmpty.value
    && fileList.filteredDirs.length === 0
    && fileList.filteredFiles.length === 0
    && !fileList.loading,
)

function goUp() {
  const parent = navigation.currentPath.slice(0, -1)
  navigation.navigateTo(parent)
}

function sortIcon(column: string) {
  if (fileList.sortBy !== column) return null
  return fileList.sortAsc ? ArrowUp : ArrowDown
}

function handleEmptyClick(e: MouseEvent) {
  if (e.target === e.currentTarget) {
    fileList.clearSelection()
  }
}

function handleListClick(e: MouseEvent) {
  const target = e.target as HTMLElement
  if (target.closest('[data-file-row]')) return
  fileList.clearSelection()
}

const constrainedStyle = computed(() => {
  const maxW = preferences.effectiveTableMaxWidth
  if (maxW == null) return {}
  return { maxWidth: `${maxW}px` }
})

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
</script>

<template>
  <div class="flex flex-col h-full">
    <!-- Header -->
    <div class="border-b bg-muted/30 sticky top-0 z-10 relative" @click="handleEmptyClick">
      <div class="flex items-center px-3 text-xs font-medium text-muted-foreground" :style="{ ...constrainedStyle, height: 'var(--fc-row-height, 32px)' }">
        <div class="w-6 shrink-0" />
        <div
          class="flex items-center gap-1 flex-1 min-w-0 cursor-pointer hover:text-foreground"
          @click="fileList.toggleSort('name')"
        >
          Name
          <component :is="sortIcon('name')" v-if="sortIcon('name')" class="size-3" />
        </div>
        <div
          class="w-20 text-right cursor-pointer hover:text-foreground flex items-center justify-end gap-1"
          @click="fileList.toggleSort('size')"
        >
          Size
          <component :is="sortIcon('size')" v-if="sortIcon('size')" class="size-3" />
        </div>
        <div
          class="w-24 text-right cursor-pointer hover:text-foreground flex items-center justify-end gap-1"
          @click="fileList.toggleSort('mtime')"
        >
          Modified
          <component :is="sortIcon('mtime')" v-if="sortIcon('mtime')" class="size-3" />
        </div>
      </div>
      <!-- Resize handle on header -->
      <div
        v-if="preferences.effectiveTableMaxWidth != null"
        class="absolute top-0 bottom-0 w-1.5 cursor-col-resize transition-colors -translate-x-1/2 group/handle z-20"
        :class="resizing ? 'bg-primary/40' : 'hover:bg-primary/30'"
        :style="{ left: `${preferences.effectiveTableMaxWidth}px` }"
        @mousedown="startResize"
      >
        <div class="absolute inset-y-0 left-1/2 w-px bg-border group-hover/handle:bg-primary/50" :class="resizing ? 'bg-primary/60' : ''" />
      </div>
    </div>

    <!-- Loading -->
    <div v-if="fileList.loading" class="flex-1 flex items-center justify-center">
      <Loader2 class="size-5 animate-spin text-muted-foreground" />
    </div>

    <!-- Error -->
    <div v-else-if="fileList.error" class="flex-1 flex flex-col items-center justify-center gap-2 text-sm text-destructive">
      <p>{{ fileList.error }}</p>
    </div>

    <!-- File list with context menu on empty area -->
    <ContextMenu v-else>
      <ContextMenuTrigger as-child>
        <div class="flex-1 overflow-auto relative" @click="handleListClick">
          <!-- Go up row -->
          <div
            v-if="!isRoot"
            class="flex items-center px-3 text-xs text-muted-foreground cursor-pointer hover:bg-muted/50"
            style="height: var(--fc-row-height, 32px)"
            :style="constrainedStyle"
            data-file-row
            @click="goUp"
          >
            <div class="w-6 shrink-0" />
            <div class="flex items-center gap-2 flex-1">
              <CornerLeftUp class="size-4" />
              <span>..</span>
            </div>
            <div class="w-20" />
            <div class="w-24" />
          </div>

          <FileRow
            v-for="d in fileList.filteredDirs"
            :key="'d-' + d.name"
            :name="d.name"
            :is-dir="true"
            :mtime="d.mtime"
          />
          <FileRow
            v-for="f in fileList.filteredFiles"
            :key="'f-' + f.name"
            :name="f.name"
            :is-dir="false"
            :size="f.size"
            :mtime="f.mtime"
          />

          <!-- Empty state -->
          <div v-if="isFilteredEmpty" class="flex flex-col items-center justify-center py-16 text-muted-foreground">
            <p class="text-sm">No matches for "{{ ui.searchQuery }}"</p>
          </div>
          <div v-else-if="isActuallyEmpty" class="flex flex-col items-center justify-center py-16 text-muted-foreground">
            <p class="text-sm">This folder is empty</p>
            <p v-if="!ui.readonly" class="text-xs mt-1">Drag files here to upload</p>
          </div>

        </div>
      </ContextMenuTrigger>

      <ContextMenuContent class="w-52">
        <template v-if="!ui.readonly">
          <ContextMenuItem v-if="clipboard.hasContent" @select="pasteItems(navigation.currentPathStr)">
            <ClipboardPaste class="size-4" />
            Paste
            <ContextMenuShortcut>Ctrl+V</ContextMenuShortcut>
          </ContextMenuItem>
          <ContextMenuSeparator v-if="clipboard.hasContent" />
          <ContextMenuItem @select="createItem('file')">
            <File class="size-4" />
            New File
          </ContextMenuItem>
          <ContextMenuItem @select="createItem('dir')">
            <Folder class="size-4" />
            New Folder
          </ContextMenuItem>
          <ContextMenuSeparator />
        </template>
        <ContextMenuItem @select="fileList.toggleSelectAll()">
          <CheckSquare v-if="!fileList.isAllSelected" class="size-4" />
          <Square v-else class="size-4" />
          {{ fileList.isAllSelected ? 'Deselect All' : 'Select All' }}
          <ContextMenuShortcut>Ctrl+A</ContextMenuShortcut>
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>

    <!-- Status / Batch bar -->
    <BatchActionBar v-if="fileList.hasSelection" />
    <div
      v-else-if="!fileList.loading && !fileList.error"
      class="flex items-center h-9 px-3 text-xs shrink-0"
      style="background: var(--statusbar-bg); color: var(--statusbar-fg); border-top: 1px solid var(--statusbar-border)"
    >
      {{ fileList.dirs.length }} folders, {{ fileList.files.length }} files
      <span class="ml-1">({{ formatSize(fileList.totalSize) }})</span>
      <span class="flex-1"></span>
      <span class="text-muted-foreground/60">FileCarton by Zbx1425</span>
    </div>
  </div>
</template>
