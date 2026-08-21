<script setup lang="ts">
import { ref, watch } from 'vue'
import { lightboxState, closeLightbox } from '@/composables/useLightbox'
import { X } from '@lucide/vue'

const scale = ref(1)
const translateX = ref(0)
const translateY = ref(0)
let isDragging = false
let hasDragged = false
let dragStartX = 0
let dragStartY = 0
let startTranslateX = 0
let startTranslateY = 0

watch(() => lightboxState.open, (open) => {
  if (open) {
    scale.value = 1
    translateX.value = 0
    translateY.value = 0
  }
})

function handleWheel(e: WheelEvent) {
  e.preventDefault()
  const delta = e.deltaY > 0 ? 0.9 : 1.1
  const newScale = Math.max(0.1, Math.min(10, scale.value * delta))
  scale.value = newScale
}

function handleMouseDown(e: MouseEvent) {
  if (e.button !== 0) return
  isDragging = true
  hasDragged = false
  dragStartX = e.clientX
  dragStartY = e.clientY
  startTranslateX = translateX.value
  startTranslateY = translateY.value
  e.preventDefault()
}

function handleMouseMove(e: MouseEvent) {
  if (!isDragging) return
  const dx = e.clientX - dragStartX
  const dy = e.clientY - dragStartY
  if (Math.abs(dx) > 3 || Math.abs(dy) > 3) hasDragged = true
  translateX.value = startTranslateX + dx
  translateY.value = startTranslateY + dy
}

function handleMouseUp() {
  isDragging = false
}

function handleBackdropClick(e: MouseEvent) {
  if (hasDragged) {
    hasDragged = false
    return
  }
  if (e.target === e.currentTarget) closeLightbox()
}

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') closeLightbox()
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="lightboxState.open"
      class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center cursor-move"
      @click="handleBackdropClick"
      @wheel.prevent="handleWheel"
      @mousedown="handleMouseDown"
      @mousemove="handleMouseMove"
      @mouseup="handleMouseUp"
      @mouseleave="handleMouseUp"
      @keydown="handleKeydown"
      tabindex="0"
      ref="containerRef"
    >
      <button
        class="absolute top-4 right-4 text-white/70 hover:text-white z-10"
        @click="closeLightbox"
      >
        <X class="size-6" />
      </button>

      <img
        :src="lightboxState.src"
        :alt="lightboxState.alt"
        class="max-w-none select-none pointer-events-none"
        :style="{
          transform: `translate(${translateX}px, ${translateY}px) scale(${scale})`,
        }"
        draggable="false"
      />

      <div class="absolute bottom-4 left-1/2 -translate-x-1/2 text-white/70 text-xs">
        {{ lightboxState.alt }}
      </div>
    </div>
  </Teleport>
</template>
