<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { registerAnchoredMenu } from './registry'

defineProps<{
  contentClass?: string
}>()

const open = ref(false)
const activeKey = ref<string | null>(null)
const suppressNextClick = ref(false)
const suppressKey = ref<string | null>(null)
const openGeneration = ref(0)
const sourceEl = ref<HTMLElement | null>(null)
const anchorEl = ref<HTMLElement | null>(null)
const anchorStyle = ref<Record<string, string>>({})

let registration: ReturnType<typeof registerAnchoredMenu> | null = null
let detachFollow: (() => void) | null = null
let focusTimer = 0
let suppressTimer = 0
let settleTimer = 0
// True only after the close animation has finished. While false, another press
// on the same button must not mount the content again.
let settledClosed = true

function close() {
  open.value = false
  suppressNextClick.value = false
  suppressKey.value = null
}

function isExpanded(key: string) {
  return open.value && activeKey.value === key
}

function scrollParent(el: HTMLElement | null): HTMLElement | null {
  let node = el?.parentElement ?? null
  while (node) {
    const style = getComputedStyle(node)
    if (/(auto|scroll)/.test(style.overflowY) || /(auto|scroll)/.test(style.overflowX)) return node
    node = node.parentElement
  }
  return null
}

function syncAnchor() {
  const el = sourceEl.value
  if (!el || !el.isConnected) {
    open.value = false
    return
  }
  const rect = el.getBoundingClientRect()
  anchorStyle.value = {
    position: 'fixed',
    left: `${rect.left}px`,
    top: `${rect.top}px`,
    width: `${rect.width}px`,
    height: `${rect.height}px`,
    margin: '0',
    padding: '0',
    border: '0',
    boxSizing: 'border-box',
    opacity: '0',
    pointerEvents: 'none',
  }
}

function detach() {
  detachFollow?.()
  detachFollow = null
}

function attach() {
  detach()
  const onMove = () => syncAnchor()
  window.addEventListener('scroll', onMove, { capture: true, passive: true })
  window.addEventListener('resize', onMove)
  const viewport = window.visualViewport
  viewport?.addEventListener('resize', onMove)
  viewport?.addEventListener('scroll', onMove)
  const parent = scrollParent(sourceEl.value)
  const resizeObserver = new ResizeObserver(onMove)
  if (parent) resizeObserver.observe(parent)
  const host = sourceEl.value?.parentElement
  const mutationObserver = new MutationObserver(() => {
    if (!sourceEl.value?.isConnected) open.value = false
  })
  if (host) mutationObserver.observe(host, { childList: true })
  detachFollow = () => {
    window.removeEventListener('scroll', onMove, true)
    window.removeEventListener('resize', onMove)
    viewport?.removeEventListener('resize', onMove)
    viewport?.removeEventListener('scroll', onMove)
    resizeObserver.disconnect()
    mutationObserver.disconnect()
  }
}

function clearSuppress() {
  suppressNextClick.value = false
  suppressKey.value = null
  window.clearTimeout(suppressTimer)
}

function onPointerDown(key: string, event: PointerEvent) {
  if (event.button !== 0) return
  // Outside dismiss can set `open` false before this handler, including while
  // an animation is still on screen. Key off the row, not the current flag.
  if (activeKey.value === key && !settledClosed) {
    suppressNextClick.value = true
    suppressKey.value = key
    open.value = false
    window.clearTimeout(suppressTimer)
    suppressTimer = window.setTimeout(() => {
      if (suppressKey.value === key) clearSuppress()
    }, 700)
  } else {
    event.stopPropagation()
  }
  const button = event.currentTarget
  if (!(button instanceof HTMLElement)) return
  const onCancel = () => {
    button.removeEventListener('pointercancel', onCancel)
    if (suppressKey.value === key) clearSuppress()
  }
  button.addEventListener('pointercancel', onCancel)
}

async function onClick(key: string, event: MouseEvent, commitTarget: () => void) {
  event.stopPropagation()
  const sameRow = key === activeKey.value
  const closingThisPress = suppressNextClick.value && key === suppressKey.value
  if (sameRow && (!settledClosed || closingThisPress)) {
    clearSuppress()
    open.value = false
    return
  }
  clearSuppress()
  const button = event.currentTarget
  if (!(button instanceof HTMLElement)) return
  const switching = open.value && activeKey.value !== key
  if (switching) {
    open.value = false
    await nextTick()
  }
  commitTarget()
  sourceEl.value = button
  syncAnchor()
  activeKey.value = key
  registration?.closeOthers()
  openGeneration.value += 1
  open.value = true
}

function release(key: string) {
  if (activeKey.value === key) close()
}

function focusSource() {
  if (anchorEl.value && document.activeElement === anchorEl.value) anchorEl.value.blur()
  if (sourceEl.value?.isConnected) sourceEl.value.focus({ preventScroll: true })
}

function onCloseAutoFocus(event: Event) {
  event.preventDefault()
  focusSource()
  window.clearTimeout(focusTimer)
  focusTimer = window.setTimeout(focusSource, 0)
}

function markSettled() {
  if (open.value) return
  settledClosed = true
  window.clearTimeout(settleTimer)
}

function onAnimationEnd(event: AnimationEvent) {
  const node = event.currentTarget
  if (!(node instanceof Element) || event.target !== node) return
  if (node.getAnimations().some(animation => animation.playState === 'running')) return
  markSettled()
}

watch(open, (isOpen) => {
  if (isOpen) {
    settledClosed = false
    window.clearTimeout(settleTimer)
    attach()
  } else {
    detach()
    // Fallback only when the browser never emits animationend (duration 0).
    window.clearTimeout(settleTimer)
    settleTimer = window.setTimeout(markSettled, 1000)
  }
})

onMounted(() => {
  registration = registerAnchoredMenu(close)
})

onBeforeUnmount(() => {
  registration?.unregister()
  detach()
  window.clearTimeout(focusTimer)
  window.clearTimeout(suppressTimer)
  window.clearTimeout(settleTimer)
})

defineExpose({ isExpanded, onPointerDown, onClick, release })
</script>

<template>
  <DropdownMenu v-model:open="open">
    <Teleport to="body">
      <DropdownMenuTrigger as-child>
        <span
          ref="anchorEl"
          tabindex="-1"
          aria-hidden="true"
          :style="anchorStyle"
        />
      </DropdownMenuTrigger>
      <DropdownMenuContent
        :key="openGeneration"
        side="bottom"
        align="end"
        :class="contentClass"
        @close-auto-focus="onCloseAutoFocus"
        @animationend="onAnimationEnd"
      >
        <slot />
      </DropdownMenuContent>
    </Teleport>
  </DropdownMenu>
</template>
