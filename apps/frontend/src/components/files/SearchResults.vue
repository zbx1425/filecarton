<script setup lang="ts">
import { ref, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import { apiGet } from '@/api/client'
import type { SearchResponse, SearchResult } from '@/api/types'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import { getFileIcon, getFileIconColor } from '@/composables/useFileType'
import { formatSize } from '@/utils/format'
import { splitPath } from '@/utils/path'
import { Loader2, Search } from '@lucide/vue'

const navigation = useNavigationStore()
const ui = useUiStore()

const results = ref<SearchResult[]>([])
const loading = ref(false)
const truncated = ref(false)
const scanLimitReached = ref(false)
const error = ref<string | null>(null)

async function doSearch(query: string) {
  if (!query.trim()) {
    results.value = []
    return
  }
  loading.value = true
  error.value = null
  try {
    const data = await apiGet<SearchResponse>('search', {
      path: navigation.currentPathStr,
      q: query.trim(),
      limit: '200',
    })
    results.value = data.results
    truncated.value = data.truncated
    scanLimitReached.value = data.scanLimitReached ?? false
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Search failed'
  } finally {
    loading.value = false
  }
}

const debouncedSearch = useDebounceFn(doSearch, 300)

watch(() => ui.searchQuery, (q) => {
  if (ui.searchRecursive) {
    debouncedSearch(q)
  }
})

watch(() => ui.searchRecursive, (recursive) => {
  if (recursive && ui.searchQuery) {
    doSearch(ui.searchQuery)
  }
})

async function openResult(result: SearchResult) {
  const dirSegments = result.path ? splitPath(result.path) : []
  if (result.type === 'dir') {
    await navigation.navigateTo([...dirSegments, result.name])
  } else {
    await navigation.navigateTo(dirSegments)
    await navigation.openFile(result.name)
  }
  ui.searchQuery = ''
  ui.searchRecursive = false
}

function navigateToDir(path: string) {
  const segments = path ? splitPath(path) : []
  navigation.navigateTo(segments)
  ui.searchQuery = ''
  ui.searchRecursive = false
}
</script>

<template>
  <div class="flex flex-col h-full">
    <div class="flex items-center px-3 h-8 text-xs text-muted-foreground border-b bg-muted/30">
      <Search class="size-3.5 mr-2" />
      Searching in /{{ navigation.currentPathStr || '(root)' }} for "{{ ui.searchQuery }}"
    </div>

    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <Loader2 class="size-5 animate-spin text-muted-foreground" />
    </div>

    <div v-else-if="error" class="flex-1 flex items-center justify-center text-sm text-destructive">
      {{ error }}
    </div>

    <div v-else-if="results.length === 0" class="flex-1 flex items-center justify-center text-sm text-muted-foreground">
      No results found
    </div>

    <div v-else class="flex-1 overflow-auto">
      <div
        v-for="result in results"
        :key="result.path + '/' + result.name"
        class="flex items-center px-3 text-xs cursor-pointer hover:bg-muted/50 gap-2"
        style="height: var(--fc-row-height, 32px)"
        @click="openResult(result)"
      >
        <component
          :is="getFileIcon(result.name, result.type === 'dir')"
          class="size-4 shrink-0"
          :class="getFileIconColor(result.name, result.type === 'dir')"
        />
        <span class="truncate font-medium">{{ result.name }}</span>
        <span
          class="text-muted-foreground truncate cursor-pointer hover:underline"
          @click.stop="navigateToDir(result.path)"
        >
          /{{ result.path || '(root)' }}
        </span>
        <span v-if="result.size != null" class="text-muted-foreground shrink-0 tabular-nums ml-auto">
          {{ formatSize(result.size) }}
        </span>
      </div>

      <div v-if="truncated || scanLimitReached" class="px-3 py-2 text-xs text-muted-foreground text-center border-t space-y-0.5">
        <p v-if="truncated">Results truncated. Refine your search for more specific results.</p>
        <p v-if="scanLimitReached">Too many files to scan, results may be incomplete. Try searching within a subdirectory.</p>
      </div>
    </div>
  </div>
</template>
