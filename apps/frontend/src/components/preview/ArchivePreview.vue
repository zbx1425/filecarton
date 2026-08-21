<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { apiGet } from '@/api/client'
import type { ArchiveListResponse } from '@/api/types'
import { formatSize } from '@/utils/format'
import { Loader2, FileArchive, Folder, File } from '@lucide/vue'

const props = defineProps<{
  path: string
}>()

const loading = ref(true)
const error = ref<string | null>(null)
const data = ref<ArchiveListResponse | null>(null)

onMounted(async () => {
  try {
    data.value = await apiGet<ArchiveListResponse>('archive_list', { path: props.path })
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Failed to load archive'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="p-4">
    <div v-if="loading" class="flex items-center justify-center py-8">
      <Loader2 class="size-5 animate-spin text-muted-foreground" />
    </div>

    <div v-else-if="error" class="text-center py-8 text-sm text-destructive">
      {{ error }}
    </div>

    <template v-else-if="data">
      <div class="flex items-center gap-3 mb-4 text-xs text-muted-foreground">
        <FileArchive class="size-4" />
        <span>{{ data.totalFiles }} files</span>
        <span>{{ formatSize(data.totalSize) }} uncompressed</span>
        <span>{{ formatSize(data.compressedSize) }} compressed</span>
      </div>

      <div class="border divide-y text-xs max-h-[60vh] overflow-auto">
        <div
          v-for="entry in data.entries"
          :key="entry.path"
          class="flex items-center h-7 px-3 gap-2"
        >
          <Folder v-if="entry.isDir" class="size-3.5 text-muted-foreground shrink-0" />
          <File v-else class="size-3.5 text-muted-foreground shrink-0" />
          <span class="truncate flex-1 font-mono">{{ entry.path }}</span>
          <span v-if="!entry.isDir" class="text-muted-foreground tabular-nums shrink-0">
            {{ formatSize(entry.size) }}
          </span>
        </div>
      </div>
    </template>
  </div>
</template>
