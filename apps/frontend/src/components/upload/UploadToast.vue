<script setup lang="ts">
import { computed } from 'vue'
import { Progress } from '@/components/ui/progress'
import { useUploadStore } from '@/stores/upload'
import {
  Loader2, CheckCircle2, AlertTriangle, Upload,
} from '@lucide/vue'

const upload = useUploadStore()

const totalProgress = computed(() => {
  const total = upload.activeTotal
  if (total === 0) return 0
  const sum = upload.tasks
    .filter(t => t.status !== 'error')
    .reduce((s, t) => s + t.progress, 0)
  return Math.min(100, Math.round(sum / total))
})

const result = computed(() => upload.latestBatchResult)
const hasErrors = computed(() => upload.errorTasks.length > 0)
const latestBatchAllSuccess = computed(() => result.value != null && result.value.failed === 0)
</script>

<template>
  <div class="w-[300px] select-none">
    <!-- Active uploads -->
    <div v-if="upload.hasActive" class="flex items-center gap-2.5">
      <Loader2 class="size-4 animate-spin text-primary shrink-0" />
      <div class="flex-1 min-w-0">
        <div class="text-sm font-medium">Uploading {{ upload.activeTotal }} file(s)...</div>
        <Progress :model-value="totalProgress" class="h-1.5 mt-1" />
      </div>
      <span class="text-xs tabular-nums text-muted-foreground shrink-0 w-8 text-right">{{ totalProgress }}%</span>
    </div>

    <!-- Done: show latest batch result -->
    <template v-else-if="result">
      <!-- Latest batch result line -->
      <div class="flex items-center gap-2.5">
        <CheckCircle2 v-if="latestBatchAllSuccess" class="size-4 text-fc-success shrink-0" />
        <AlertTriangle v-else class="size-4 text-destructive shrink-0" />
        <div class="flex-1 min-w-0 text-sm font-medium">
          <template v-if="latestBatchAllSuccess">
            {{ result.total }} file(s) uploaded
          </template>
          <template v-else>
            {{ result.success }} uploaded, {{ result.failed }} failed
          </template>
        </div>
      </div>

      <!-- Error detail link -->
      <div v-if="hasErrors" class="mt-1.5 ml-[26px]">
        <div
          v-if="latestBatchAllSuccess && upload.oldErrorCount > 0"
          class="text-xs text-muted-foreground"
        >
          {{ upload.oldErrorCount }} earlier upload(s) still failed
        </div>
        <button
          class="text-xs text-primary hover:underline cursor-pointer mt-0.5"
          @click="upload.openErrorDialog()"
        >
          View details
        </button>
      </div>
    </template>

    <!-- Fallback: no result yet but errors exist (e.g. after retry cleared batch result) -->
    <div v-else-if="hasErrors" class="flex items-center gap-2.5">
      <AlertTriangle class="size-4 text-destructive shrink-0" />
      <div class="flex-1 min-w-0">
        <div class="text-sm font-medium">{{ upload.errorTasks.length }} upload(s) failed</div>
        <button
          class="text-xs text-primary hover:underline cursor-pointer mt-0.5"
          @click="upload.openErrorDialog()"
        >
          View details
        </button>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else class="flex items-center gap-2.5">
      <Upload class="size-4 text-muted-foreground shrink-0" />
      <div class="text-sm text-muted-foreground">No active uploads</div>
    </div>
  </div>
</template>
