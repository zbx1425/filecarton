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
}>()

const icon = computed(() => getFileIcon(props.name, false))
</script>

<template>
  <div class="flex flex-col items-center justify-center p-8 gap-4 h-full">
    <component :is="icon" class="size-16 text-muted-foreground" />
    <p class="text-sm font-mono">{{ name }}</p>
    <p class="text-xs text-muted-foreground">{{ formatSize(size) }}</p>
    <Button variant="outline" as="a" :href="buildDownloadUrl(path)" target="_blank">
      <Download class="size-4" />
      Download
    </Button>
  </div>
</template>
