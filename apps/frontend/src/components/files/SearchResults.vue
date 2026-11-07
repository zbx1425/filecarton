<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import { apiGet, toErrorInfo } from '@/api/client'
import type { ErrorInfo } from '@/api/client'
import type { SearchResponse, SearchResult } from '@/api/types'
import { useNavigationStore } from '@/stores/navigation'
import { usePreferencesStore } from '@/stores/preferences'
import { useUiStore } from '@/stores/ui'
import { getFileIcon, getFileIconColor } from '@/composables/useFileType'
import { formatSize } from '@/utils/format'
import { splitPath, joinPath } from '@/utils/path'
import { Loader2, Search } from '@lucide/vue'

const navigation = useNavigationStore()
const prefs = usePreferencesStore()
const ui = useUiStore()

const rawResults = ref<SearchResult[]>([])
const results = computed(() => {
  if (prefs.showDotFiles) return rawResults.value
  return rawResults.value.filter(r => {
    if (r.name.startsWith('.')) return false
    if (r.path) {
      const segments = r.path.split('/')
      if (segments.some(s => s.startsWith('.'))) return false
    }
    return true
  })
})
const loading = ref(false)
const truncated = ref(false)
const scanLimitReached = ref(false)
const error = ref<ErrorInfo | null>(null)
let searchSeq = 0

async function doSearch(query: string) {
  if (!query.trim()) {
    rawResults.value = []
    return
  }
  const seq = ++searchSeq
  loading.value = true
  error.value = null
  try {
    const data = await apiGet<SearchResponse>('search', {
      path: navigation.currentPathStr,
      q: query.trim(),
      limit: '200',
    })
    if (seq !== searchSeq) return
    rawResults.value = data.results
    truncated.value = data.truncated
    scanLimitReached.value = data.scanLimitReached ?? false
  } catch (e: unknown) {
    if (seq !== searchSeq) return
    error.value = toErrorInfo(e)
  } finally {
    if (seq === searchSeq) loading.value = false
  }
}

const debouncedSearch = useDebounceFn(doSearch, 300)

watch(() => navigation.searchQuery, (q) => {
  if (navigation.searchRecursive) {
    debouncedSearch(q)
  }
})

watch(() => navigation.searchRecursive, (recursive) => {
  if (recursive && navigation.searchQuery) {
    doSearch(navigation.searchQuery)
  }
}, { immediate: true })

async function openResult(result: SearchResult) {
  const dirSegments = result.path ? splitPath(result.path) : []
  if (result.type === 'dir') {
    const ok = await navigation.navigateTo([...dirSegments, result.name])
    if (!ok) return
  } else {
    const ok = await navigation.navigateTo(dirSegments)
    if (!ok) return
    const filePath = joinPath(result.path || '', result.name)
    await navigation.openFile(filePath)
  }
  navigation.searchQuery = ''
  navigation.searchRecursive = false
}

function navigateToDir(path: string) {
  const segments = path ? splitPath(path) : []
  navigation.navigateTo(segments).then(ok => {
    if (!ok) return
    navigation.searchQuery = ''
    navigation.searchRecursive = false
  })
}
</script>

<template>
  <div class="flex flex-col h-full">
    <div class="flex items-center px-3 h-8 text-sm text-muted-foreground border-b bg-muted/30">
      <Search class="size-3.5 mr-2" />
      {{ navigation.currentPathStr ? $t('files.search.banner', { path: navigation.currentPathStr, query: navigation.searchQuery }) : $t('files.search.banner_root', { query: navigation.searchQuery }) }}
    </div>

    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <Loader2 class="size-5 animate-spin text-muted-foreground" />
    </div>

    <div v-else-if="error" class="flex-1 flex flex-col items-center justify-center gap-2 text-sm text-destructive">
      <p>{{ $t('err.' + error.code, error.params ?? {}) }}</p>
      <button class="text-sm underline text-muted-foreground hover:text-foreground" @click="doSearch(navigation.searchQuery)">
        {{ $t('common.action.retry') }}
      </button>
    </div>

    <div v-else-if="results.length === 0" class="flex-1 flex items-center justify-center text-sm text-muted-foreground">
      {{ $t('files.search.no_results') }}
    </div>

    <div v-else class="flex-1 overflow-auto">
      <template v-for="result in results" :key="result.path + '/' + result.name">
      <div
        v-if="ui.isMobile"
        class="flex min-h-14 cursor-pointer flex-col justify-center gap-0.5 px-3 py-1.5 text-sm"
        @click="openResult(result)"
      >
        <div class="flex min-w-0 items-center gap-2">
          <component
            :is="getFileIcon(result.name, result.type === 'dir')"
            class="size-4 shrink-0"
            :class="getFileIconColor(result.name, result.type === 'dir')"
          />
          <span class="truncate font-medium">{{ result.name }}</span>
        </div>
        <div class="flex min-w-0 items-center text-xs text-muted-foreground">
          <span
            class="min-w-0 flex-1 truncate hover:underline"
            @click.stop="navigateToDir(result.path)"
          >
            /{{ result.path || $t('common.path.root') }}
          </span>
          <span v-if="result.size != null" class="w-14 shrink-0 text-right tabular-nums">
            {{ formatSize(result.size) }}
          </span>
        </div>
      </div>
      <div
        v-else
        class="flex items-center px-3 text-sm cursor-pointer hover:bg-muted/50 gap-2 h-(--fc-row-height)"
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
          /{{ result.path || $t('common.path.root') }}
        </span>
        <span v-if="result.size != null" class="text-muted-foreground shrink-0 tabular-nums ml-auto">
          {{ formatSize(result.size) }}
        </span>
      </div>
      </template>

      <div v-if="truncated || scanLimitReached" class="px-3 py-2 text-sm text-muted-foreground text-center border-t space-y-0.5">
        <p v-if="truncated">{{ $t('files.search.truncated') }}</p>
        <p v-if="scanLimitReached">{{ $t('files.search.scan_limit') }}</p>
      </div>
    </div>
  </div>
</template>
