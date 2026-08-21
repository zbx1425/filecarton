<script setup lang="ts">
import { computed, watch } from 'vue'
import { ArrowUp, ArrowDown, CornerLeftUp, Loader2, ClipboardPaste, File, Folder, Upload, CheckSquare } from '@lucide/vue'
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
import { formatSize } from '@/utils/format'
import { createItem, pasteItems } from '@/composables/useFileActions'
import FileRow from './FileRow.vue'
import BatchActionBar from './BatchActionBar.vue'

const fileList = useFileListStore()
const navigation = useNavigationStore()
const clipboard = useClipboardStore()
const ui = useUiStore()

const isRoot = computed(() => navigation.currentPath.length === 0)

const filteredDirs = computed(() => {
  const q = ui.searchQuery.trim().toLowerCase()
  if (!q || ui.searchRecursive) return fileList.sortedDirs
  return fileList.sortedDirs.filter(d => d.name.toLowerCase().includes(q))
})

const filteredFiles = computed(() => {
  const q = ui.searchQuery.trim().toLowerCase()
  if (!q || ui.searchRecursive) return fileList.sortedFiles
  return fileList.sortedFiles.filter(f => f.name.toLowerCase().includes(q))
})

const isEmpty = computed(
  () => filteredDirs.value.length === 0 && filteredFiles.value.length === 0 && !fileList.loading,
)

function goUp() {
  const parent = navigation.currentPath.slice(0, -1)
  navigation.navigateTo(parent)
}

function sortIcon(column: string) {
  if (fileList.sortBy !== column) return null
  return fileList.sortAsc ? ArrowUp : ArrowDown
}

watch(
  () => navigation.currentPathStr,
  (path) => fileList.fetchDir(path),
  { immediate: true },
)
</script>

<template>
  <div class="flex flex-col h-full">
    <!-- Header -->
    <div class="flex items-center h-8 px-3 text-xs font-medium text-muted-foreground border-b bg-muted/30 sticky top-0 z-10">
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
        <div class="flex-1 overflow-auto">
          <!-- Go up row -->
          <div
            v-if="!isRoot"
            class="flex items-center h-8 px-3 text-xs text-muted-foreground cursor-pointer hover:bg-muted/50"
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
            v-for="d in filteredDirs"
            :key="'d-' + d.name"
            :name="d.name"
            :is-dir="true"
            :mtime="d.mtime"
          />
          <FileRow
            v-for="f in filteredFiles"
            :key="'f-' + f.name"
            :name="f.name"
            :is-dir="false"
            :size="f.size"
            :mtime="f.mtime"
          />

          <!-- Empty state -->
          <div v-if="isEmpty" class="flex flex-col items-center justify-center py-16 text-muted-foreground">
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
        <ContextMenuItem @select="fileList.selectAll()">
          <CheckSquare class="size-4" />
          Select All
          <ContextMenuShortcut>Ctrl+A</ContextMenuShortcut>
        </ContextMenuItem>
      </ContextMenuContent>
    </ContextMenu>

    <!-- Status / Batch bar -->
    <BatchActionBar v-if="fileList.hasSelection" />
    <div v-else-if="!fileList.loading && !fileList.error" class="flex items-center h-7 px-3 text-xs text-muted-foreground border-t bg-muted/20 shrink-0">
      {{ fileList.dirs.length }} folders, {{ fileList.files.length }} files
      <span class="ml-1">({{ formatSize(fileList.totalSize) }})</span>
    </div>
  </div>
</template>
