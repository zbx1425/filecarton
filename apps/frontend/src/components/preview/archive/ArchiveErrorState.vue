<script setup lang="ts">
import { FileArchive, Download } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { buildDownloadUrl } from '@/api/client'
import type { ErrorInfo } from '@/api/client'
import { formatSize } from '@/utils/format'
import ArchiveExtractButton from './ArchiveExtractButton.vue'

const props = defineProps<{
  archivePath: string
  archiveName: string
  size?: number
  error: ErrorInfo
}>()

function handleDownloadArchive() {
  window.open(buildDownloadUrl(props.archivePath), '_blank')
}
</script>

<template>
  <div class="flex-1 flex flex-col items-center justify-center p-8 gap-4 text-center">
    <FileArchive class="size-16 text-muted-foreground" />
    <p class="text-sm font-mono">{{ archiveName }}</p>
    <p v-if="size" class="text-sm text-muted-foreground">{{ formatSize(size) }}</p>
    <p class="text-sm text-destructive max-w-md">
      {{ $t('err.' + error.code, error.params ?? {}) }}
    </p>
    <div class="flex items-center gap-2 mt-1">
      <ArchiveExtractButton
        v-if="error.code === 'archive.too_large_to_open'"
        :path="archivePath"
        :name="archiveName"
      />
      <Button variant="outline" size="sm" @click="handleDownloadArchive">
        <Download class="size-4" />
        Download
      </Button>
    </div>
  </div>
</template>
