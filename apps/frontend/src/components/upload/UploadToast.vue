<script setup lang="ts">
import { computed } from 'vue'
import { Progress } from '@/components/ui/progress'
import { Button } from '@/components/ui/button'
import { useUploadStore, type UploadTask } from '@/stores/upload'
import { formatSize } from '@/utils/format'
import {
  Loader2, CheckCircle2, XCircle, Upload, X, RotateCcw,
} from '@lucide/vue'

const upload = useUploadStore()

const uploading = computed(() => upload.tasks.filter(t => t.status === 'uploading'))
const pending = computed(() => upload.tasks.filter(t => t.status === 'pending'))
const completed = computed(() => upload.tasks.filter(t => t.status === 'success' || t.status === 'error'))

const sortedTasks = computed<UploadTask[]>(() => [
  ...uploading.value,
  ...pending.value,
  ...completed.value,
])

const totalProgress = computed(() => {
  const all = upload.tasks
  if (all.length === 0) return 0
  return Math.round(all.reduce((sum, t) => sum + t.progress, 0) / all.length)
})

const successCount = computed(() => upload.tasks.filter(t => t.status === 'success').length)
const errorCount = computed(() => upload.tasks.filter(t => t.status === 'error').length)
const activeCount = computed(() => upload.tasks.filter(t => t.status === 'uploading' || t.status === 'pending').length)
</script>

<template>
  <div class="w-[340px]">
    <div class="flex items-center gap-2 pb-2 border-b border-border/50">
      <Upload class="size-4 text-primary shrink-0" />
      <div class="flex-1 min-w-0">
        <div class="text-sm font-medium">
          <template v-if="activeCount > 0">
            Uploading {{ activeCount }} file(s)...
          </template>
          <template v-else>
            Upload complete
          </template>
        </div>
        <div class="text-xs text-muted-foreground">
          {{ successCount }} done<template v-if="errorCount > 0">, {{ errorCount }} failed</template>
        </div>
      </div>
      <Progress v-if="activeCount > 0" :model-value="totalProgress" class="w-16 h-1.5" />
      <span v-if="activeCount > 0" class="text-xs tabular-nums text-muted-foreground">{{ totalProgress }}%</span>
    </div>

    <div class="max-h-[200px] overflow-y-auto -mx-1 px-1 py-1">
      <div
        v-for="task in sortedTasks"
        :key="task.id"
        class="flex items-center gap-1.5 py-1 text-xs"
      >
        <Loader2 v-if="task.status === 'uploading'" class="size-3 animate-spin text-primary shrink-0" />
        <CheckCircle2 v-else-if="task.status === 'success'" class="size-3 text-fc-success shrink-0" />
        <XCircle v-else-if="task.status === 'error'" class="size-3 text-destructive shrink-0" />
        <Upload v-else class="size-3 text-muted-foreground shrink-0" />

        <span class="truncate flex-1" :title="task.relativePath || task.file.name">
          {{ task.relativePath || task.file.name }}
        </span>
        <span class="text-muted-foreground shrink-0 tabular-nums">{{ formatSize(task.file.size) }}</span>

        <div v-if="task.status === 'uploading'" class="w-10 shrink-0">
          <Progress :model-value="task.progress" class="h-1" />
        </div>

        <Button
          v-if="task.status === 'error'"
          variant="ghost"
          size="icon-xs"
          class="size-5"
          @click="upload.retry(task.id)"
        >
          <RotateCcw class="size-2.5" />
        </Button>

        <Button
          variant="ghost"
          size="icon-xs"
          class="size-5"
          @click="upload.remove(task.id)"
        >
          <X class="size-2.5" />
        </Button>
      </div>
    </div>
  </div>
</template>
