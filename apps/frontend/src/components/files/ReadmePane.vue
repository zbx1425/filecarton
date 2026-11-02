<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { Button } from '@/components/ui/button'
import { apiGet, toErrorInfo, buildDownloadUrl } from '@/api/client'
import type { ErrorInfo } from '@/api/client'
import type { ReadResponse } from '@/api/types'
import { usePreferencesStore } from '@/stores/preferences'
import { useNavigationStore } from '@/stores/navigation'
import { Download, ExternalLink, ChevronsDown, ChevronsUp, Loader2, FileText } from '@lucide/vue'
import MarkdownContent from '@/components/preview/MarkdownContent.vue'
import { pathExtension } from '@/utils/path'

const props = defineProps<{
  name: string
  path: string
}>()

const prefs = usePreferencesStore()
const navigation = useNavigationStore()

const content = ref('')
const loading = ref(true)
const error = ref<ErrorInfo | null>(null)

const isMarkdownFile = computed(() => pathExtension(props.name) === 'md')

const TITLE_BAR_HEIGHT = 40
const MIN_CONTENT_HEIGHT = 192
const MIN_PANE_HEIGHT = TITLE_BAR_HEIGHT + MIN_CONTENT_HEIGHT
const MIN_FILE_LIST_HEIGHT = 192

const containerRef = ref<HTMLElement | null>(null)
const availableHeight = ref(600)
let resizeObserver: ResizeObserver | null = null

const windowTooSmall = computed(() =>
  availableHeight.value < MIN_FILE_LIST_HEIGHT + MIN_PANE_HEIGHT,
)

const effectivelyCollapsed = computed(() =>
  prefs.readmePaneCollapsed || windowTooSmall.value,
)

const maxPaneHeight = computed(() =>
  Math.max(MIN_PANE_HEIGHT, availableHeight.value - MIN_FILE_LIST_HEIGHT),
)

const effectivePaneHeight = computed(() => {
  if (effectivelyCollapsed.value) return TITLE_BAR_HEIGHT
  return Math.max(MIN_PANE_HEIGHT, Math.min(prefs.readmePaneHeight, maxPaneHeight.value))
})

function toggleCollapse() {
  prefs.readmePaneCollapsed = !prefs.readmePaneCollapsed
}

const resizing = ref(false)

function startResize(e: MouseEvent) {
  if (effectivelyCollapsed.value) return
  e.preventDefault()
  resizing.value = true
  const startY = e.clientY
  const startHeight = effectivePaneHeight.value

  function onMouseMove(ev: MouseEvent) {
    const delta = startY - ev.clientY
    const newHeight = Math.max(MIN_PANE_HEIGHT, Math.min(maxPaneHeight.value, startHeight + delta))
    prefs.readmePaneHeight = newHeight
  }

  function onMouseUp() {
    resizing.value = false
    document.removeEventListener('mousemove', onMouseMove)
    document.removeEventListener('mouseup', onMouseUp)
  }

  document.addEventListener('mousemove', onMouseMove)
  document.addEventListener('mouseup', onMouseUp)
}

function updateAvailableHeight() {
  const el = containerRef.value?.parentElement
  if (el) {
    availableHeight.value = el.clientHeight
  }
}

async function fetchContent() {
  loading.value = true
  error.value = null
  try {
    const data = await apiGet<ReadResponse>('read', { path: props.path })
    content.value = data.content
  } catch (e: unknown) {
    error.value = toErrorInfo(e)
  } finally {
    loading.value = false
  }
}

watch(() => props.path, () => fetchContent())

watch(() => navigation.viewMode, (mode, oldMode) => {
  if (mode === 'list' && oldMode !== 'list') fetchContent()
})

onMounted(() => {
  fetchContent()
  updateAvailableHeight()
  resizeObserver = new ResizeObserver(() => updateAvailableHeight())
  const parent = containerRef.value?.parentElement
  if (parent) resizeObserver.observe(parent)
})

onBeforeUnmount(() => {
  resizeObserver?.disconnect()
})
</script>

<template>
  <div
    ref="containerRef"
    class="shrink-0 flex flex-col border-t"
    :style="{ height: effectivePaneHeight + 'px' }"
  >
    <!-- Drag handle -->
    <div
      v-if="!effectivelyCollapsed"
      class="h-[3px] shrink-0 transition-colors"
      :class="[
        resizing ? 'bg-primary/40 cursor-row-resize' : 'cursor-row-resize hover:bg-primary/30',
      ]"
      @mousedown="startResize"
    />

    <!-- Title bar -->
    <div class="flex items-center h-10 px-3 gap-2 shrink-0 bg-muted/30">
      <FileText class="size-4 text-muted-foreground shrink-0" />
      <span class="text-sm font-mono truncate flex-1 text-muted-foreground">{{ name }}</span>

      <Button
        variant="outline"
        size="sm"
        as="a"
        :href="buildDownloadUrl(path)"
        target="_blank"
      >
        <Download class="size-4" />
        Download
      </Button>

      <Button
        variant="outline"
        size="sm"
        @click="navigation.openFile(name)"
      >
        <ExternalLink class="size-4" />
        Open
      </Button>

      <Button
        variant="ghost"
        size="icon-sm"
        :aria-label="effectivelyCollapsed ? 'Expand README' : 'Collapse README'"
        @click="toggleCollapse"
      >
        <ChevronsUp v-if="effectivelyCollapsed" class="size-4" />
        <ChevronsDown v-else class="size-4" />
      </Button>
    </div>

    <!-- Content -->
    <div v-if="!effectivelyCollapsed" class="flex-1 min-h-0 overflow-auto">
      <div v-if="loading" class="flex items-center justify-center py-8">
        <Loader2 class="size-5 animate-spin text-muted-foreground" />
      </div>
      <div v-else-if="error" class="flex items-center justify-center py-8 text-sm text-destructive">
        {{ $t('err.' + error.code, error.params ?? {}) }}
      </div>
      <template v-else>
        <MarkdownContent v-if="isMarkdownFile" :content="content" />
        <pre v-else class="px-6 py-4 text-sm font-mono whitespace-pre-wrap break-words">{{ content }}</pre>
      </template>
    </div>
  </div>
</template>
