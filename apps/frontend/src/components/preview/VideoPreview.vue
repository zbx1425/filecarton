<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { buildRawUrl, buildDownloadUrl } from '@/api/client'
import { formatSize, formatDuration } from '@/utils/format'
import { Video, Download } from '@lucide/vue'

const props = defineProps<{
  path: string
  name: string
  size: number
}>()

const videoSrc = computed(() => buildRawUrl(props.path))
const downloadUrl = computed(() => buildDownloadUrl(props.path))

const videoWidth = ref(0)
const videoHeight = ref(0)
const duration = ref(0)
const playbackError = ref(false)

watch(
  () => props.path,
  () => {
    videoWidth.value = 0
    videoHeight.value = 0
    duration.value = 0
    playbackError.value = false
  },
)

function handleLoadedMetadata(e: Event) {
  const video = e.target as HTMLVideoElement
  videoWidth.value = video.videoWidth
  videoHeight.value = video.videoHeight
  duration.value = video.duration
}

function handleError() {
  playbackError.value = true
}
</script>

<template>
  <div class="flex flex-col items-center justify-center p-6 gap-4 h-full">
    <template v-if="!playbackError">
      <div
        class="bg-black/95 rounded-lg border shadow-sm overflow-hidden flex items-center justify-center max-w-full max-h-[calc(100%-3rem)]"
      >
        <video
          :key="videoSrc"
          :src="videoSrc"
          controls
          preload="metadata"
          playsinline
          class="max-w-full max-h-[70vh] outline-none"
          @loadedmetadata="handleLoadedMetadata"
          @error="handleError"
        ></video>
      </div>

      <div class="flex items-center gap-3 text-xs text-muted-foreground tabular-nums">
        <span>{{ formatSize(size) }}</span>
        <span v-if="videoWidth && videoHeight">{{ videoWidth }} × {{ videoHeight }}</span>
        <span v-if="duration > 0">{{ formatDuration(duration) }}</span>
      </div>
    </template>

    <template v-else>
      <Video class="size-16 text-muted-foreground" />
      <p class="text-sm font-mono">{{ name }}</p>
      <p class="text-xs text-muted-foreground">
        This video format or codec cannot be played directly in your browser ({{ formatSize(size) }}).
      </p>
      <Button variant="outline" as="a" :href="downloadUrl" target="_blank">
        <Download class="size-4" />
        Download
      </Button>
    </template>
  </div>
</template>
