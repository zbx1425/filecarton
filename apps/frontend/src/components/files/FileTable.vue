<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { ArrowUp, ArrowDown, CornerLeftUp, Loader2, ClipboardPaste, File, Folder, CloudDownload, CheckSquare, Square, RefreshCw } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuShortcut,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { useUiStore } from '@/stores/ui'
import { useFileListView } from '@/composables/useFileListView'
import { formatSize } from '@/utils/format'
import { createItem, pasteItems } from '@/composables/useFileActions'
import { showRemoteDownloadDialog } from '@/composables/useDialogs'
import FileRow from './FileRow.vue'
import BatchActionBar from './BatchActionBar.vue'
import ReadmePane from './ReadmePane.vue'
import { joinPath } from '@/utils/path'

const view = reactive(useFileListView())
const ui = useUiStore()

const isRoot = computed(() => view.currentPath.length === 0)

const isActuallyEmpty = computed(
  () => view.dirs.length === 0 && view.files.length === 0 && !view.loading,
)

const isFilteredEmpty = computed(
  () => !isActuallyEmpty.value
    && view.filteredDirs.length === 0
    && view.filteredFiles.length === 0
    && !view.loading,
)

function sortIcon(column: string) {
  if (view.sortBy !== column) return null
  return view.sortAsc ? ArrowUp : ArrowDown
}

const readmeFile = computed(() => {
  let readme: string | null = null
  for (const f of view.files) {
    const lower = f.name.toLowerCase()
    if (lower === 'readme.md') return f.name
    if (lower === 'readme' && !readme) readme = f.name
  }
  return readme
})

const readmePath = computed(() => {
  if (!readmeFile.value) return ''
  return joinPath(view.currentPathStr, readmeFile.value)
})

function handleEmptyClick(e: MouseEvent) {
  if (e.target === e.currentTarget) {
    view.clearSelection()
  }
}

function handleListClick(e: MouseEvent) {
  const target = e.target as HTMLElement
  if (target.closest('[data-file-row]')) return
  view.clearSelection()
}

const resizing = ref(false)

function startResize(e: MouseEvent) {
  e.preventDefault()
  resizing.value = true
  const startX = e.clientX
  const startWidth = view.effectiveTableMaxWidth ?? e.clientX

  function onMouseMove(ev: MouseEvent) {
    const delta = ev.clientX - startX
    const newWidth = Math.max(400, startWidth + delta)
    if (newWidth > window.innerWidth - 48) {
      view.setTableMaxWidth(null)
    } else {
      view.setTableMaxWidth(newWidth)
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
      <div class="flex items-center px-3 text-sm font-medium text-muted-foreground h-(--fc-row-height) max-w-(--fc-table-max-w)">
        <div class="w-6 shrink-0" />
        <div
          class="flex items-center gap-1 flex-1 min-w-0 cursor-pointer hover:text-foreground"
          @click="view.toggleSort('name')"
        >
          Name
          <component :is="sortIcon('name')" v-if="sortIcon('name')" class="size-3" />
        </div>
        <div
          class="w-22 cursor-pointer hover:text-foreground flex items-center gap-1"
          @click="view.toggleSort('size')"
        >
          Size
          <component :is="sortIcon('size')" v-if="sortIcon('size')" class="size-3" />
        </div>
        <div
          class="w-28 cursor-pointer hover:text-foreground flex items-center gap-1"
          @click="view.toggleSort('mtime')"
        >
          Modified
          <component :is="sortIcon('mtime')" v-if="sortIcon('mtime')" class="size-3" />
        </div>
        <template v-if="view.showUnixPerm">
          <div class="w-28 shrink-0">
            Permissions
          </div>
          <div class="w-36 shrink-0">
            Owner
          </div>
        </template>
      </div>
      <!-- Resize handle on header -->
      <div
        v-if="view.effectiveTableMaxWidth != null"
        class="absolute top-0 bottom-0 left-(--fc-table-resize-left) w-1.5 cursor-col-resize transition-colors -translate-x-1/2 group/handle z-20"
        :class="resizing ? 'bg-primary/40' : 'hover:bg-primary/30'"
        @mousedown="startResize"
      >
        <div class="absolute inset-y-0 left-1/2 w-px bg-border group-hover/handle:bg-primary/50" :class="resizing ? 'bg-primary/60' : ''" />
      </div>
    </div>

    <!-- Main content area: file list + optional readme pane -->
    <div class="flex-1 min-h-0 flex flex-col">
      <!-- Loading -->
      <div v-if="view.loading" class="flex-1 flex items-center justify-center">
        <Loader2 class="size-5 animate-spin text-muted-foreground" />
      </div>

      <!-- Error -->
      <div v-else-if="view.error" class="flex-1 flex flex-col items-center justify-center gap-2 text-sm text-destructive">
        <p>{{ $t('err.' + view.error.code, view.error.params ?? {}) }}</p>
        <Button variant="outline" size="sm" @click="view.refreshCurrent()">
          Retry
        </Button>
      </div>

      <!-- File list with context menu on empty area -->
      <ContextMenu v-else>
        <ContextMenuTrigger as-child>
          <div class="flex-1 min-h-48 overflow-auto relative" @click="handleListClick">
            <!-- Go up row -->
            <div
              v-if="!isRoot"
              class="flex items-center px-3 text-sm text-muted-foreground cursor-pointer hover:bg-muted/50 h-(--fc-row-height) max-w-(--fc-table-max-w)"
              data-file-row
              @click="view.goUp"
            >
              <div class="w-6 shrink-0" />
              <div class="flex items-center gap-2 flex-1">
                <CornerLeftUp class="size-4" />
                <span>..</span>
              </div>
              <div class="w-22" />
              <div class="w-28" />
              <template v-if="view.showUnixPerm">
                <div class="w-28" />
                <div class="w-36" />
              </template>
            </div>

            <FileRow
              v-for="d in view.filteredDirs"
              :key="'d-' + d.name"
              :name="d.name"
              :is-dir="true"
              :mtime="d.mtime"
              :mode="d.mode"
              :owner="d.owner"
              :group="d.group"
            />
            <FileRow
              v-for="f in view.filteredFiles"
              :key="'f-' + f.name"
              :name="f.name"
              :is-dir="false"
              :size="f.size"
              :mtime="f.mtime"
              :mode="f.mode"
              :owner="f.owner"
              :group="f.group"
            />

            <!-- Empty state -->
            <div v-if="isFilteredEmpty" class="flex flex-col items-center justify-center py-16 text-muted-foreground">
              <p v-if="ui.searchQuery.trim()" class="text-sm">No matches for "{{ ui.searchQuery }}"</p>
              <p v-else class="text-sm">All files under this directory are hidden files</p>
            </div>
            <div v-else-if="isActuallyEmpty" class="flex flex-col items-center justify-center py-16 text-muted-foreground">
              <p class="text-sm">This folder is empty</p>
              <p v-if="!ui.readonly" class="text-sm mt-1">Drag files here to upload</p>
            </div>

          </div>
        </ContextMenuTrigger>

        <ContextMenuContent class="w-52">
          <ContextMenuItem @select="view.refreshCurrent">
            <RefreshCw class="size-4" />
            Refresh
          </ContextMenuItem>
          <ContextMenuSeparator />
          <template v-if="!ui.readonly">
            <ContextMenuItem v-if="view.canPaste" @select="pasteItems(view.currentPathStr)">
              <ClipboardPaste class="size-4" />
              Paste
              <ContextMenuShortcut>Ctrl+V</ContextMenuShortcut>
            </ContextMenuItem>
            <ContextMenuSeparator v-if="view.canPaste" />
            <ContextMenuItem @select="createItem('file')">
              <File class="size-4" />
              New File
            </ContextMenuItem>
            <ContextMenuItem @select="createItem('dir')">
              <Folder class="size-4" />
              New Folder
            </ContextMenuItem>
            <ContextMenuItem v-if="ui.features.remoteDownload" @select="showRemoteDownloadDialog()">
              <CloudDownload class="size-4" />
              Remote Download
            </ContextMenuItem>
            <ContextMenuSeparator />
          </template>
          <ContextMenuItem @select="view.toggleSelectAll()">
            <CheckSquare v-if="!view.isAllSelected" class="size-4" />
            <Square v-else class="size-4" />
            {{ view.isAllSelected ? 'Deselect All' : 'Select All' }}
            <ContextMenuShortcut>Ctrl+A</ContextMenuShortcut>
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <!-- README preview pane -->
      <ReadmePane
        v-if="readmeFile && !view.loading && !view.error"
        :name="readmeFile"
        :path="readmePath"
      />
    </div>

    <!-- Status / Batch bar -->
    <BatchActionBar v-if="view.hasSelection" />
    <div
      v-else-if="!view.loading && !view.error"
      class="flex items-center h-9 px-3 text-sm shrink-0 bg-statusbar text-statusbar-foreground border-t border-statusbar-border"
    >
      {{ view.filteredDirs.length }} folders, {{ view.filteredFiles.length }} files
      <span class="ml-1">({{ formatSize(view.filteredTotalSize) }})</span>
      <span class="flex-1"></span>
      <span class="text-muted-foreground/60">FileCarton by Zbx1425</span>
    </div>
  </div>
</template>
