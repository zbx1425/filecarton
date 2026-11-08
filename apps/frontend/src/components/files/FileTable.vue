<script setup lang="ts">
import { computed, inject, provide, ref } from 'vue'
import { ArrowUp, ArrowDown, CornerLeftUp, Loader2 } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { ContextMenu, ContextMenuTrigger } from '@/components/ui/context-menu'
import { useUiStore } from '@/stores/ui'
import { formatSize } from '@/utils/format'
import { joinPath } from '@/utils/path'
import FileRow from './FileRow.vue'
import FileListContextMenu from './FileListContextMenu.vue'
import BatchActionBar from './BatchActionBar.vue'
import ReadmePane from './ReadmePane.vue'
import { FILE_LIST_KEY, FILE_LIST_VIEW_KEY, type FileListContext, type ContextTargetItem } from './fileListContext'
import { AnchoredMenu, ANCHORED_MENU_KEY, type AnchoredMenuHandle } from '@/components/ui/anchored-menu'
import FileListMenuBody from './FileListMenuBody.vue'

const view = inject(FILE_LIST_VIEW_KEY) ?? missingFileListView()
const ui = useUiStore()

function missingFileListView(): never {
  throw new Error('FileTable requires FILE_LIST_VIEW_KEY')
}

// ── Provide context to child components ──

const fileListContext: FileListContext = {
  toggleSelect: (name: string) => view.toggleSelect(name),
  rangeSelect: (name: string) => view.rangeSelect(name),
  clearSelection: () => view.clearSelection(),
  setFocusByName: (name: string) => view.setFocusByName(name),
  navigateTo: (path: string[]) => view.navigateTo(path),
  openFile: (fileName: string) => view.openFile(fileName),
  isSelected: (name: string) => view.isSelected(name),
  isCutItem: (name: string) => view.isCutItem(name),
  get currentPath() { return view.currentPath },
  get currentPathStr() { return view.currentPathStr },
  get selectedCount() { return view.selectedCount },
  get canPaste() { return view.canPaste },
  get showUnixPerm() { return view.showUnixPerm },
  get isAllSelected() { return view.isAllSelected },
  toggleSelectAll: () => view.toggleSelectAll(),
}

provide(FILE_LIST_KEY, fileListContext)

// ── Computed state ──

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

// ── Context menu target ──

const contextTarget = ref<ContextTargetItem | null>(null)
const overflowTarget = ref<ContextTargetItem | null>(null)
const rowMenu = ref<AnchoredMenuHandle | null>(null)

provide(ANCHORED_MENU_KEY, {
  isExpanded: (key) => rowMenu.value?.isExpanded(key) ?? false,
  onPointerDown: (key, event) => rowMenu.value?.onPointerDown(key, event),
  onClick: (key, event, commitTarget) => rowMenu.value?.onClick(key, event, commitTarget),
  release: (key) => rowMenu.value?.release(key),
})

function handleRowContext(_e: MouseEvent, item: ContextTargetItem) {
  if (!view.isSelected(item.name)) {
    view.clearSelection()
    view.toggleSelect(item.name)
  }
  contextTarget.value = item
}

function handleAreaContext(e: MouseEvent) {
  if (!(e.target as HTMLElement).closest('[data-file-row]')) {
    contextTarget.value = null
  }
}

// ── Click handlers ──

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

// ── Table width resize ──

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
    <div v-if="!ui.isMobile" class="border-b bg-muted/30 sticky top-0 z-10 relative" @click="handleEmptyClick">
      <div class="flex items-center px-3 text-sm font-medium text-muted-foreground h-(--fc-row-height) max-w-(--fc-table-max-w)">
        <div class="w-6 shrink-0" />
        <div
          class="flex items-center gap-1 flex-1 min-w-0 cursor-pointer hover:text-foreground"
          @click="view.toggleSort('name')"
        >
          {{ $t('common.field.name') }}
          <component :is="sortIcon('name')" v-if="sortIcon('name')" class="size-3" />
        </div>
        <div
          class="w-22 cursor-pointer hover:text-foreground flex items-center gap-1"
          @click="view.toggleSort('size')"
        >
          {{ $t('common.field.size') }}
          <component :is="sortIcon('size')" v-if="sortIcon('size')" class="size-3" />
        </div>
        <div
          class="w-28 cursor-pointer hover:text-foreground flex items-center gap-1"
          @click="view.toggleSort('mtime')"
        >
          {{ $t('common.field.modified') }}
          <component :is="sortIcon('mtime')" v-if="sortIcon('mtime')" class="size-3" />
        </div>
        <template v-if="view.showUnixPerm">
          <div class="w-28 shrink-0">{{ $t('files.action.permissions') }}</div>
          <div class="w-36 shrink-0">{{ $t('common.field.owner') }}</div>
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
          {{ $t('common.action.retry') }}
        </Button>
      </div>

      <!-- File list with unified context menu -->
      <ContextMenu v-else>
        <ContextMenuTrigger as-child>
          <div class="flex-1 min-h-48 overflow-auto relative" @click="handleListClick" @contextmenu="handleAreaContext">
            <!-- Go up row -->
            <div
              v-if="!isRoot"
              class="flex items-center px-3 text-sm text-muted-foreground cursor-pointer hover:bg-muted/50"
              :class="ui.isMobile ? 'min-h-(--fc-mobile-row-height) touch-manipulation' : 'h-(--fc-row-height) max-w-(--fc-table-max-w)'"
              data-file-row
              @click="view.goUp"
              @contextmenu="contextTarget = null"
            >
              <div class="shrink-0" :class="ui.isMobile ? 'w-11' : 'w-6'" />
              <div class="flex min-w-0 flex-1 items-center gap-2">
                <CornerLeftUp class="size-4" />
                <span>..</span>
              </div>
              <template v-if="!ui.isMobile">
                <div class="w-22" />
                <div class="w-28" />
                <template v-if="view.showUnixPerm">
                  <div class="w-28" />
                  <div class="w-36" />
                </template>
              </template>
              <div v-else class="w-11 shrink-0" />
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
              @contextmenu="handleRowContext($event, { name: d.name, isDir: true, mode: d.mode, owner: d.owner, group: d.group })"
              @overflow="overflowTarget = { name: d.name, isDir: true, mode: d.mode, owner: d.owner, group: d.group }"
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
              @contextmenu="handleRowContext($event, { name: f.name, isDir: false, size: f.size, mode: f.mode, owner: f.owner, group: f.group })"
              @overflow="overflowTarget = { name: f.name, isDir: false, size: f.size, mode: f.mode, owner: f.owner, group: f.group }"
            />

            <!-- Empty state -->
            <div v-if="isFilteredEmpty" class="flex flex-col items-center justify-center py-16 text-muted-foreground">
              <p v-if="view.searchQuery?.trim()" class="text-sm">{{ $t('files.empty.no_matches', { query: view.searchQuery }) }}</p>
              <p v-else class="text-sm">{{ $t('files.empty.hidden_only') }}</p>
            </div>
            <div v-else-if="isActuallyEmpty" class="flex flex-col items-center justify-center py-16 text-muted-foreground">
              <p class="text-sm">{{ $t('files.empty.empty') }}</p>
              <p v-if="!ui.readonly" class="text-sm mt-1">{{ $t('files.empty.drop_hint') }}</p>
            </div>

          </div>
        </ContextMenuTrigger>

        <FileListContextMenu :context-target="contextTarget" />
      </ContextMenu>

      <AnchoredMenu v-if="ui.isMobile" ref="rowMenu" content-class="w-52">
        <FileListMenuBody
          v-if="overflowTarget"
          surface="dropdown"
          :show-shortcuts="false"
          :flatten-subs="true"
          :context-target="overflowTarget"
        />
      </AnchoredMenu>

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
      class="flex min-w-0 items-center h-9 px-3 text-sm shrink-0 bg-statusbar text-statusbar-foreground border-t border-statusbar-border"
    >
      <span class="min-w-0 truncate">
        {{ $t('files.status.summary', { folders: view.filteredDirs.length, files: view.filteredFiles.length }) }}
        <span class="ml-1">({{ formatSize(view.filteredTotalSize) }})</span>
      </span>
      <span class="ml-auto shrink-0 text-muted-foreground/60">FileCarton by Zbx1425</span>
    </div>
  </div>
</template>
