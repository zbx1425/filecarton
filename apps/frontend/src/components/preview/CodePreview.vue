<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import type * as Monaco from 'monaco-editor'
import { apiGet } from '@/api/client'
import type { ReadResponse } from '@/api/types'
import { loadMonaco } from '@/composables/useMonaco'
import { monacoLanguage } from '@/composables/useFileType'
import { Loader2 } from '@lucide/vue'
import { usePreferencesStore } from '@/stores/preferences'

const props = defineProps<{
  path: string
  name: string
}>()

const prefs = usePreferencesStore()

const containerRef = ref<HTMLElement | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
let editor: Monaco.editor.IStandaloneCodeEditor | null = null
let resizeObserver: ResizeObserver | null = null

onMounted(async () => {
  try {
    const [monaco, fileData] = await Promise.all([
      loadMonaco(),
      apiGet<ReadResponse>('read', { path: props.path }),
    ])

    if (!containerRef.value) return

    editor = monaco.editor.create(containerRef.value, {
      value: fileData.content,
      language: monacoLanguage(props.name),
      theme: prefs.resolvedTheme === 'dark' ? 'vs-dark' : 'vs',
      readOnly: true,
      minimap: { enabled: false },
      wordWrap: prefs.editorWordWrap ? 'on' : 'off',
      fontSize: prefs.editorFontSize,
      lineNumbers: prefs.editorLineNumbers ? 'on' : 'off',
      scrollBeyondLastLine: false,
      automaticLayout: false,
      mouseWheelZoom: true,
    })

    resizeObserver = new ResizeObserver(() => editor?.layout())
    resizeObserver.observe(containerRef.value)

    watch(() => prefs.resolvedTheme, (t) => {
      editor?.updateOptions({ theme: t === 'dark' ? 'vs-dark' : 'vs' })
    })
    watch(() => prefs.editorFontSize, (s) => editor?.updateOptions({ fontSize: s }))
    watch(() => prefs.editorWordWrap, (w) => editor?.updateOptions({ wordWrap: w ? 'on' : 'off' }))
    watch(() => prefs.editorLineNumbers, (l) => editor?.updateOptions({ lineNumbers: l ? 'on' : 'off' }))
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Failed to load file'
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  resizeObserver?.disconnect()
  editor?.dispose()
})
</script>

<template>
  <div class="h-full relative">
    <div v-if="loading" class="absolute inset-0 flex items-center justify-center">
      <Loader2 class="size-5 animate-spin text-muted-foreground" />
    </div>
    <div v-if="error" class="absolute inset-0 flex items-center justify-center text-sm text-destructive">
      {{ error }}
    </div>
    <div ref="containerRef" class="h-full" />
  </div>
</template>
