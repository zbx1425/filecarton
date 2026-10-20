<script setup lang="ts">
import { computed } from 'vue'
import { Button } from '@/components/ui/button'
import { getFileIcon } from '@/composables/useFileType'
import { formatSize } from '@/utils/format'
import { buildDownloadUrl } from '@/api/client'
import { Download } from '@lucide/vue'

const props = defineProps<{
  name: string
  size: number
  path: string
  downloadUrl?: string | null
  hint?: string
}>()

const icon = computed(() => getFileIcon(props.name, false))
const effectiveDownloadUrl = computed(() =>
  props.downloadUrl === undefined ? buildDownloadUrl(props.path) : props.downloadUrl,
)
</script>

<template>
  <div class="flex flex-col items-center justify-center p-8 gap-4 h-full text-center">
    <component :is="icon" class="size-16 text-muted-foreground" />
    <p class="text-sm font-mono">{{ name }}</p>
    <p class="text-sm text-muted-foreground">{{ formatSize(size) }}</p>
    <p v-if="hint" class="text-sm text-muted-foreground max-w-md">{{ hint }}</p>
    <Button v-if="effectiveDownloadUrl" variant="outline" as="a" :href="effectiveDownloadUrl" target="_blank">
      <Download class="size-4" />
      Download
    </Button>
  </div>
</template>
