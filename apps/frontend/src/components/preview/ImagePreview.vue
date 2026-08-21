<script setup lang="ts">
import { ref, computed } from 'vue'
import { buildRawUrl } from '@/api/client'
import { canPreviewImage } from '@/composables/useFileType'
import { openLightbox } from '@/composables/useLightbox'
import { formatSize } from '@/utils/format'
import { FileImage } from '@lucide/vue'

const props = defineProps<{
  path: string
  name: string
  size: number
}>()

const canPreview = computed(() => canPreviewImage(props.name, props.size))
const imgSrc = computed(() => buildRawUrl(props.path))

const naturalWidth = ref(0)
const naturalHeight = ref(0)

function handleLoad(e: Event) {
  const img = e.target as HTMLImageElement
  naturalWidth.value = img.naturalWidth
  naturalHeight.value = img.naturalHeight
}

function handleClick() {
  openLightbox(imgSrc.value, props.name)
}
</script>

<template>
  <div class="flex flex-col items-center justify-center p-8 gap-4 h-full">
    <template v-if="canPreview">
      <div
        class="cursor-zoom-in max-w-full max-h-[calc(100%-4rem)]"
        style="background-image: conic-gradient(#eee 25%, transparent 25%, transparent 75%, #eee 75%),
               conic-gradient(#eee 25%, transparent 25%, transparent 75%, #eee 75%);
               background-size: 16px 16px;
               background-position: 0 0, 8px 8px;"
        @click="handleClick"
      >
        <img
          :src="imgSrc"
          :alt="name"
          class="max-w-full max-h-[60vh] object-contain"
          @load="handleLoad"
        />
      </div>
      <div class="text-xs text-muted-foreground space-x-3">
        <span>{{ formatSize(size) }}</span>
        <span v-if="naturalWidth">{{ naturalWidth }} × {{ naturalHeight }}</span>
      </div>
    </template>

    <template v-else>
      <FileImage class="size-16 text-muted-foreground" />
      <p class="text-sm text-muted-foreground">{{ name }}</p>
      <p class="text-xs text-muted-foreground">
        File too large to preview ({{ formatSize(size) }}). Use Download.
      </p>
    </template>
  </div>
</template>
