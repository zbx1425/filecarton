<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount, computed } from 'vue'
import type * as Monaco from 'monaco-editor'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { apiGet, apiPost } from '@/api/client'
import { ApiError } from '@/api/client'
import type { ReadResponse, WriteResponse } from '@/api/types'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import { loadMonaco } from '@/composables/useMonaco'
import { monacoLanguage } from '@/composables/useFileType'
import { showEditorConflict } from '@/composables/useDialogs'
import { ArrowLeft, Save, Loader2, ClipboardCopy, Download } from '@lucide/vue'
import { buildDownloadUrl } from '@/api/client'
import { usePreferencesStore } from '@/stores/preferences'
import EditorStatusBar from './EditorStatusBar.vue'

const navigation = useNavigationStore()
const ui = useUiStore()
const prefs = usePreferencesStore()

const containerRef = ref<HTMLElement | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const saving = ref(false)

const editorInstance = ref<Monaco.editor.IStandaloneCodeEditor | null>(null)
let editor: Monaco.editor.IStandaloneCodeEditor | null = null
let resizeObserver: ResizeObserver | null = null
let currentMtime = 0

const filePath = computed(() => navigation.activeFilePath ?? '')
const fileName = computed(() => navigation.activeFile ?? '')

async function copyAll() {
  if (!editor) return
  try {
    await navigator.clipboard.writeText(editor.getValue())
    toast.success('Copied to clipboard')
  } catch {
    toast.error('Failed to copy')
  }
}

async function save() {
  if (!editor || saving.value || ui.readonly) return

  saving.value = true
  try {
    const result = await apiPost<WriteResponse>('write', {
      path: filePath.value,
      content: editor.getValue(),
      expectedMtime: currentMtime,
    })
    currentMtime = result.mtime
    navigation.editDirty = false
    toast.success('File saved')
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 409) {
      const choice = await showEditorConflict()
      if (choice === 'overwrite') {
        try {
          const result = await apiPost<WriteResponse>('write', {
            path: filePath.value,
            content: editor!.getValue(),
          })
          currentMtime = result.mtime
          navigation.editDirty = false
          toast.success('File saved (overwritten)')
        } catch (e2: unknown) {
          toast.error(e2 instanceof Error ? e2.message : 'Save failed')
        }
      } else if (choice === 'reload') {
        await reloadContent()
      }
    } else {
      toast.error(e instanceof Error ? e.message : 'Save failed')
    }
  } finally {
    saving.value = false
  }
}

async function reloadContent() {
  if (!editor) return
  try {
    const data = await apiGet<ReadResponse>('read', { path: filePath.value })
    editor.setValue(data.content)
    currentMtime = data.mtime
    navigation.editDirty = false
    toast.info('File reloaded')
  } catch (e: unknown) {
    toast.error(e instanceof Error ? e.message : 'Failed to reload')
  }
}

onMounted(async () => {
  try {
    const [monaco, fileData] = await Promise.all([
      loadMonaco(),
      apiGet<ReadResponse>('read', { path: filePath.value }),
    ])

    currentMtime = fileData.mtime

    if (!containerRef.value) return

    editor = monaco.editor.create(containerRef.value, {
      value: fileData.content,
      language: monacoLanguage(fileName.value),
      theme: prefs.resolvedTheme === 'dark' ? 'vs-dark' : 'vs',
      readOnly: ui.readonly,
      minimap: { enabled: false },
      wordWrap: prefs.editorWordWrap ? 'on' : 'off',
      fontSize: prefs.editorFontSize,
      lineNumbers: prefs.editorLineNumbers ? 'on' : 'off',
      scrollBeyondLastLine: false,
      automaticLayout: false,
      tabSize: prefs.editorTabSize,
      detectIndentation: prefs.editorAutoDetect,
      mouseWheelZoom: true,
    })
    editorInstance.value = editor

    editor.onDidChangeModelContent(() => {
      navigation.editDirty = true
    })

    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
      save()
    })

    resizeObserver = new ResizeObserver(() => editor?.layout())
    resizeObserver.observe(containerRef.value)

    watch(() => prefs.resolvedTheme, (t) => {
      editor?.updateOptions({ theme: t === 'dark' ? 'vs-dark' : 'vs' })
    })
    watch(() => prefs.editorFontSize, (s) => editor?.updateOptions({ fontSize: s }))
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : 'Failed to load editor'
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
  <div class="flex flex-col h-full">
    <div class="flex items-center h-10 px-3 border-b gap-2 shrink-0">
      <Button variant="ghost" size="sm" @click="navigation.backToList()">
        <ArrowLeft class="size-4" />
        Back
      </Button>

      <span class="text-xs font-mono truncate flex-1 text-muted-foreground">
        {{ fileName }}
        <span v-if="navigation.editDirty" class="text-foreground ml-1">*</span>
      </span>

      <Button variant="ghost" size="sm" @click="copyAll">
        <ClipboardCopy class="size-4" />
        Copy All
      </Button>
      <Button variant="ghost" size="sm" as="a" :href="buildDownloadUrl(filePath)" target="_blank">
        <Download class="size-4" />
        Download
      </Button>
      <Button
        v-if="!ui.readonly"
        size="sm"
        :disabled="!navigation.editDirty || saving"
        @click="save"
      >
        <Loader2 v-if="saving" class="size-4 animate-spin" />
        <Save v-else class="size-4" />
        Save
      </Button>
    </div>

    <div class="flex-1 min-h-0 relative">
      <div v-if="loading" class="absolute inset-0 flex items-center justify-center">
        <Loader2 class="size-5 animate-spin text-muted-foreground" />
      </div>
      <div v-if="error" class="absolute inset-0 flex items-center justify-center text-sm text-destructive">
        {{ error }}
      </div>
      <div ref="containerRef" class="h-full" />
    </div>

    <EditorStatusBar
      v-if="!loading && !error"
      :editor="editorInstance"
      :language="monacoLanguage(fileName)"
    />
  </div>
</template>
