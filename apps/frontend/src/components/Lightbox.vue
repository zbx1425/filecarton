<script setup lang="ts">
import { ref, watch, nextTick } from 'vue'
import { lightboxState, closeLightbox } from '@/composables/useLightbox'
import { X } from '@lucide/vue'

const scale = ref(1)
const translateX = ref(0)
const translateY = ref(0)
const containerRef = ref<HTMLElement | null>(null)
let isDragging = false
let hasDragged = false
let dragStartX = 0
let dragStartY = 0
let startTranslateX = 0
let startTranslateY = 0
let touchMode: 'none' | 'pan' | 'pinch' = 'none'
let pinchStartDistance = 0
let pinchStartScale = 1
let pinchStartMidX = 0
let pinchStartMidY = 0
let pinchStartTranslateX = 0
let pinchStartTranslateY = 0
let lastTouchAt = 0

function touchDistance(a: Touch, b: Touch) {
  return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY)
}

function ignoreSyntheticMouse() {
  return Date.now() - lastTouchAt < 700
}

watch(() => lightboxState.open, (open) => {
  if (open) {
    scale.value = 1
    translateX.value = 0
    translateY.value = 0
    nextTick(() => containerRef.value?.focus())
  }
})

function handleWheel(e: WheelEvent) {
  e.preventDefault()
  const delta = e.deltaY > 0 ? 0.9 : 1.1
  const newScale = Math.max(0.1, Math.min(10, scale.value * delta))
  scale.value = newScale
}

function handleMouseDown(e: MouseEvent) {
  if (ignoreSyntheticMouse()) return
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

function handleTouchStart(e: TouchEvent) {
  lastTouchAt = Date.now()
  if (e.touches.length === 1) {
    touchMode = 'pan'
    isDragging = true
    hasDragged = false
    dragStartX = e.touches[0].clientX
    dragStartY = e.touches[0].clientY
    startTranslateX = translateX.value
    startTranslateY = translateY.value
  } else if (e.touches.length >= 2) {
    beginPinch(e.touches[0], e.touches[1])
  }
}

function beginPinch(a: Touch, b: Touch) {
  touchMode = 'pinch'
  isDragging = false
  pinchStartDistance = touchDistance(a, b)
  pinchStartScale = scale.value
  pinchStartMidX = (a.clientX + b.clientX) / 2
  pinchStartMidY = (a.clientY + b.clientY) / 2
  pinchStartTranslateX = translateX.value
  pinchStartTranslateY = translateY.value
}

function handleTouchMove(e: TouchEvent) {
  if (e.touches.length === 1 && touchMode === 'pan') {
    const dx = e.touches[0].clientX - dragStartX
    const dy = e.touches[0].clientY - dragStartY
    if (Math.abs(dx) > 3 || Math.abs(dy) > 3) hasDragged = true
    translateX.value = startTranslateX + dx
    translateY.value = startTranslateY + dy
  } else if (e.touches.length >= 2) {
    const a = e.touches[0]
    const b = e.touches[1]
    if (touchMode !== 'pinch') beginPinch(a, b)
    const distance = touchDistance(a, b)
    if (pinchStartDistance > 0) {
      const next = Math.max(0.1, Math.min(10, pinchStartScale * distance / pinchStartDistance))
      if (next !== pinchStartScale) hasDragged = true
      scale.value = next
    }
    const midX = (a.clientX + b.clientX) / 2
    const midY = (a.clientY + b.clientY) / 2
    const dx = midX - pinchStartMidX
    const dy = midY - pinchStartMidY
    if (Math.abs(dx) > 3 || Math.abs(dy) > 3) hasDragged = true
    translateX.value = pinchStartTranslateX + dx
    translateY.value = pinchStartTranslateY + dy
  }
}

function handleTouchEnd(e: TouchEvent) {
  if (e.touches.length === 0) {
    const tappedBackdrop = touchMode !== 'pinch'
      && !hasDragged
      && e.target === e.currentTarget
    touchMode = 'none'
    isDragging = false
    if (tappedBackdrop) closeLightbox()
    return
  }
  if (e.touches.length === 1) {
    touchMode = 'pan'
    isDragging = true
    dragStartX = e.touches[0].clientX
    dragStartY = e.touches[0].clientY
    startTranslateX = translateX.value
    startTranslateY = translateY.value
  }
}

function handleBackdropClick(e: MouseEvent) {
  if (ignoreSyntheticMouse()) return
  if (hasDragged) {
    hasDragged = false
    return
  }
  if (e.target === e.currentTarget) closeLightbox()
}

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    e.stopPropagation()
    closeLightbox()
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="lightboxState.open"
      class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center cursor-move touch-none"
      @click="handleBackdropClick"
      @wheel.prevent="handleWheel"
      @mousedown="handleMouseDown"
      @mousemove="handleMouseMove"
      @mouseup="handleMouseUp"
      @mouseleave="handleMouseUp"
      @touchstart="handleTouchStart"
      @touchmove.prevent="handleTouchMove"
      @touchend="handleTouchEnd"
      @touchcancel="handleTouchEnd"
      @keydown="handleKeydown"
      tabindex="-1"
      ref="containerRef"
    >
      <button
        class="absolute z-10 flex size-11 items-center justify-center text-white/70 hover:text-white top-[calc(env(safe-area-inset-top)+1rem)] right-[calc(env(safe-area-inset-right)+1rem)]"
        :aria-label="$t('common.action.close')"
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

      <div class="absolute left-1/2 -translate-x-1/2 text-white/70 text-sm bottom-[calc(env(safe-area-inset-bottom)+1rem)]">
        {{ lightboxState.alt }}
      </div>
    </div>
  </Teleport>
</template>
