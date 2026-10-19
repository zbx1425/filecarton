<script setup lang="ts">
import { computed, toRef } from 'vue'
import { Loader2, AlertTriangle, Info } from '@lucide/vue'
import {
  ResizableHandle,
  ResizablePanel,
  ResizablePanelGroup,
} from '@/components/ui/resizable'
import { useUiStore } from '@/stores/ui'
import { useArchiveExplorer } from '@/composables/useArchiveExplorer'
import { formatSize } from '@/utils/format'
import ArchiveToolbar from './archive/ArchiveToolbar.vue'
import ArchiveTreePanel from './archive/ArchiveTreePanel.vue'
import ArchiveFileTable from './archive/ArchiveFileTable.vue'
import ArchiveSearchResults from './archive/ArchiveSearchResults.vue'
import ArchiveEntryPreview from './archive/ArchiveEntryPreview.vue'
import ArchiveErrorState from './archive/ArchiveErrorState.vue'

const props = defineProps<{
  path: string
  name?: string
  size?: number
}>()

const ui = useUiStore()

const {
  loading,
  error,
  data,
  archiveName,
  currentPath,
  currentPathStr,
  isAtRoot,
  viewMode,
  activeEntry,
  selectedName,
  expandedPaths,
  treeVisible,
  searchQuery,
  searchRecursive,
  sortBy,
  sortAsc,
  filteredDirs,
  filteredFiles,
  statusDirsCount,
  statusFilesCount,
  statusTotalSize,
  isActuallyEmpty,
  isFilteredEmpty,
  showRecursiveSearch,
  recursiveSearchResults,
  getTreeChildren,
  toggleExpand,
  navigateTo,
  navigateToSegment,
  goUp,
  openEntryNode,
  backToArchiveList,
  toggleSort,
  downloadEntry,
} = useArchiveExplorer(toRef(props, 'path'), toRef(props, 'name'))

const shouldShowTree = computed(() => ui.isMedium && treeVisible.value)
const canExtract = computed(() => !error.value || error.value.code === 'archive.too_large_to_open')

defineExpose({ canExtract })
</script>

<template>
  <div class="flex flex-col h-full overflow-hidden">
    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <Loader2 class="size-5 animate-spin text-muted-foreground" />
    </div>

    <ArchiveErrorState
      v-else-if="error"
      :archive-path="path"
      :archive-name="archiveName"
      :size="size"
      :error="error"
    />

    <template v-else-if="data">
      <div
        v-if="data.truncated"
        class="flex items-center gap-2 px-3 py-1.5 text-xs bg-amber-500/10 text-amber-700 dark:text-amber-400 border-b shrink-0"
      >
        <AlertTriangle class="size-3.5 shrink-0" />
        <span>
          Archive file list truncated to the first {{ data.entryLimit.toLocaleString() }} entries (total {{ data.totalFiles.toLocaleString() }} files). Extract or download the archive to view all files.
        </span>
      </div>

      <div
        v-if="!data.randomAccess"
        class="flex items-center gap-2 px-3 py-1.5 text-xs bg-muted/60 text-muted-foreground border-b shrink-0"
      >
        <Info class="size-3.5 shrink-0" />
        <span>
          Single-file preview and direct download are disabled for TAR archives with many files ({{ data.totalFiles.toLocaleString() }} files). You can still browse folders or extract the archive.
        </span>
      </div>

      <ArchiveToolbar
        v-model:search-query="searchQuery"
        v-model:search-recursive="searchRecursive"
        :archive-name="archiveName"
        :current-path="currentPath"
        :active-file-name="activeEntry?.name ?? null"
        :is-at-root="isAtRoot"
        :view-mode="viewMode"
        @toggle-tree="treeVisible = !treeVisible"
        @navigate-root="navigateTo([])"
        @navigate-segment="navigateToSegment"
      />

      <ResizablePanelGroup direction="horizontal" class="flex-1 min-h-0">
        <ResizablePanel
          v-if="shouldShowTree"
          :default-size="22"
          :min-size="12"
          :max-size="45"
        >
          <ArchiveTreePanel
            :active-path="currentPathStr"
            :active-file-path="activeEntry?.path ?? null"
            :expanded-paths="expandedPaths"
            :get-children="getTreeChildren"
            :random-access="data.randomAccess"
            @toggle-expand="toggleExpand"
            @select-node="openEntryNode"
            @download-node="downloadEntry($event.path)"
          />
        </ResizablePanel>

        <ResizableHandle v-if="shouldShowTree" />

        <ResizablePanel :default-size="shouldShowTree ? 78 : 100">
          <div class="flex flex-col h-full overflow-hidden">
            <ArchiveSearchResults
              v-if="showRecursiveSearch"
              :current-path-str="currentPathStr"
              :search-query="searchQuery"
              :results="recursiveSearchResults"
              :random-access="data.randomAccess"
              @open-node="openEntryNode"
              @navigate-dir="navigateTo"
              @download-node="downloadEntry($event.path)"
            />

            <ArchiveFileTable
              v-else-if="viewMode === 'list'"
              v-model:selected-name="selectedName"
              :archive-path="path"
              :is-root="currentPath.length === 0"
              :dirs="filteredDirs"
              :files="filteredFiles"
              :sort-by="sortBy"
              :sort-asc="sortAsc"
              :is-actually-empty="isActuallyEmpty"
              :is-filtered-empty="isFilteredEmpty"
              :search-query="searchQuery"
              :random-access="data.randomAccess"
              @toggle-sort="toggleSort"
              @go-up="goUp"
              @open-node="openEntryNode"
              @download-node="downloadEntry($event.path)"
            />

            <ArchiveEntryPreview
              v-else-if="viewMode === 'preview' && activeEntry"
              :archive-path="path"
              :entry="activeEntry"
              :random-access="data.randomAccess"
              @back="backToArchiveList"
              @download="downloadEntry"
            />
          </div>
        </ResizablePanel>
      </ResizablePanelGroup>

      <div
        class="flex items-center h-9 px-3 text-xs shrink-0 gap-2"
        style="background: var(--statusbar-bg); color: var(--statusbar-fg); border-top: 1px solid var(--statusbar-border)"
      >
        <span>
          {{ statusDirsCount }} folders, {{ statusFilesCount }} files
          <span class="ml-1">({{ formatSize(statusTotalSize) }})</span>
        </span>
        <span class="flex-1" />
        <span class="text-muted-foreground/80 truncate">
          Archive total: {{ data.totalFiles }} files ({{ formatSize(data.totalSize) }} uncompressed, {{ formatSize(data.compressedSize) }} compressed)
        </span>
      </div>
    </template>
  </div>
</template>
