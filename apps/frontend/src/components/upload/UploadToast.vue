<script setup lang="ts">
import { computed } from 'vue'
import { Progress } from '@/components/ui/progress'
import { Button } from '@/components/ui/button'
import { useUploadStore } from '@/stores/upload'
import { formatSize } from '@/utils/format'
import {
  Loader2, CheckCircle2, AlertTriangle, Upload, X,
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

function safeFmtSize(bytes: number): string {
  if (!isFinite(bytes) || bytes < 1) return '0 B'
  return formatSize(bytes)
}

const sizeProgress = computed(() => {
  return `${safeFmtSize(upload.bytesSent)} / ${safeFmtSize(upload.totalBytes)}`
})

const speedText = computed(() => {
  const s = upload.speed
  if (!(s > 0)) return '—'
  return `${formatSize(s)}/s`
})

const etaText = computed(() => {
  const s = upload.eta
  if (!(s > 0) || !isFinite(s)) return '—'
  const sec = Math.ceil(s)
  if (sec < 60) return `~${sec}s`
  const m = Math.floor(sec / 60)
  const r = sec % 60
  return `~${m}:${String(r).padStart(2, '0')}`
})
</script>

<template>
  <div class="select-none min-w-0 w-full">
    <!-- Active uploads -->
    <div v-if="upload.hasActive" class="space-y-1.5">
      <div class="flex items-center gap-2.5">
        <Loader2 class="size-4 animate-spin text-primary shrink-0" />
        <div class="flex-1 min-w-0 text-sm font-medium">
          {{ $t('upload.toast.title') }}
          <span class="tabular-nums">({{ upload.completedCount }}/{{ upload.activeTotal }})</span>
        </div>
        <span class="text-sm tabular-nums text-muted-foreground shrink-0 w-10 text-right">{{ totalProgress }}%</span>
        <Button
          variant="ghost"
          size="icon-xs"
          class="size-6 shrink-0 text-muted-foreground hover:text-destructive"
          :title="$t('common.action.cancel_all')"
          @click="upload.cancelAll()"
        >
          <X class="size-3.5" />
        </Button>
      </div>
      <Progress :model-value="totalProgress" class="h-1.5" />
      <div class="grid grid-cols-4 text-xs text-muted-foreground tabular-nums">
        <span class="col-span-2">{{ sizeProgress }}</span>
        <span class="text-center">{{ speedText }}</span>
        <span class="text-right">{{ etaText }}</span>
      </div>
      <div v-if="upload.prepareDepth > 0" class="text-xs text-muted-foreground">
        {{ $t('upload.toast.preparing_more') }}
      </div>
    </div>

    <div v-else-if="upload.prepareDepth > 0" class="flex items-center gap-2.5">
      <Loader2 class="size-4 animate-spin text-primary shrink-0" />
      <div class="min-w-0 flex-1 text-sm font-medium">
        {{ upload.preparePhase === 'reading' ? $t('upload.toast.reading') : $t('upload.toast.checking') }}
      </div>
    </div>

    <!-- Done: show latest batch result -->
    <template v-else-if="result">
      <!-- Latest batch result line -->
      <div class="flex items-center gap-2.5">
        <CheckCircle2 v-if="latestBatchAllSuccess" class="size-4 text-fc-success shrink-0" />
        <AlertTriangle v-else class="size-4 text-destructive shrink-0" />
        <div class="flex-1 min-w-0 text-sm font-medium">
          <template v-if="latestBatchAllSuccess">
            {{ $t('upload.toast.done', { count: result.total }, result.total) }}
          </template>
          <template v-else>
            {{ $t('upload.toast.partial', { success: result.success, failed: result.failed }) }}
          </template>
        </div>
      </div>

      <!-- Error detail link -->
      <div v-if="hasErrors" class="mt-1.5 ml-6.5">
        <div
          v-if="latestBatchAllSuccess && upload.oldErrorCount > 0"
          class="text-sm text-muted-foreground"
        >
          {{ $t('upload.toast.earlier_failed', { count: upload.oldErrorCount }, upload.oldErrorCount) }}
        </div>
        <button
          class="text-sm text-primary hover:underline cursor-pointer mt-0.5"
          @click="upload.openErrorDialog()"
        >
          {{ $t('upload.toast.view_details') }}
        </button>
      </div>
    </template>

    <!-- Fallback: no result yet but errors exist (e.g. after retry cleared batch result) -->
    <div v-else-if="hasErrors" class="flex items-center gap-2.5">
      <AlertTriangle class="size-4 text-destructive shrink-0" />
      <div class="flex-1 min-w-0">
        <div class="text-sm font-medium">{{ $t('upload.toast.failed', { count: upload.errorTasks.length }, upload.errorTasks.length) }}</div>
        <button
          class="text-sm text-primary hover:underline cursor-pointer mt-0.5"
          @click="upload.openErrorDialog()"
        >
          {{ $t('upload.toast.view_details') }}
        </button>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else class="flex items-center gap-2.5">
      <Upload class="size-4 text-muted-foreground shrink-0" />
      <div class="text-sm text-muted-foreground">{{ $t('upload.toast.none') }}</div>
    </div>
  </div>
</template>
