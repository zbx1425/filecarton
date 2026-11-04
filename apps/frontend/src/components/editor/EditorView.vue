<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount, computed } from 'vue'
import type * as Monaco from 'monaco-editor'
import { Button } from '@/components/ui/button'
import { apiGet } from '@/api/client'
import { toErrorInfo } from '@/api/client'
import type { ErrorInfo } from '@/api/client'
import type { ReadResponse } from '@/api/types'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import { loadMonaco } from '@/composables/useMonaco'
import { monacoLanguage, isEditable } from '@/composables/useFileType'
import { useEditorSave } from '@/composables/useEditorSave'
import { ArrowLeft, Save, Loader2, ClipboardCopy, Download, Pencil } from '@lucide/vue'
import { buildDownloadUrl } from '@/api/client'
import { usePreferencesStore } from '@/stores/preferences'
import { isFilenameBlocked } from '@/utils/pathValidation'
import EditorStatusBar from './EditorStatusBar.vue'
import { toast } from 'vue-sonner'

const navigation = useNavigationStore()
const ui = useUiStore()
const prefs = usePreferencesStore()
const { saving, setMtime, save } = useEditorSave()

const containerRef = ref<HTMLElement | null>(null)
const loading = ref(true)
const error = ref<ErrorInfo | null>(null)

const editorInstance = ref<Monaco.editor.IStandaloneCodeEditor | null>(null)
let editor: Monaco.editor.IStandaloneCodeEditor | null = null
let resizeObserver: ResizeObserver | null = null

watch(() => prefs.resolvedTheme, (t) => {
  editorInstance.value?.updateOptions({ theme: t === 'dark' ? 'vs-dark' : 'vs' })
})
watch(() => prefs.editorFontSize, (s) => {
  editorInstance.value?.updateOptions({ fontSize: s })
})

const filePath = computed(() => navigation.activeFilePath ?? '')
const fileName = computed(() => navigation.activeFile ?? '')

const isReadonly = computed(() => ui.readonly || navigation.viewMode === 'preview')
const canEdit = computed(
  () => !ui.readonly && isEditable(fileName.value) && !isFilenameBlocked(fileName.value),
)

watch(isReadonly, (val) => {
  editor?.updateOptions({ readOnly: val })
})

async function copyAll() {
  if (!editor) return
  try {
    await navigator.clipboard.writeText(editor.getValue())
    toast.success('Copied to clipboard')
  } catch {
    toast.error('Failed to copy')
  }
}

async function handleSave() {
  if (!editor || isReadonly.value) return
  await save(editor, filePath.value)
}

function handleKeyDown(e: KeyboardEvent) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
    e.preventDefault()
    handleSave()
  }
}

onMounted(async () => {
  window.addEventListener('keydown', handleKeyDown)
  try {
    const [monaco, fileData] = await Promise.all([
      loadMonaco(),
      apiGet<ReadResponse>('read', { path: filePath.value }),
    ])

    setMtime(fileData.mtime)

    if (!containerRef.value) return

    editor = monaco.editor.create(containerRef.value, {
      value: fileData.content,
      language: monacoLanguage(fileName.value),
      theme: prefs.resolvedTheme === 'dark' ? 'vs-dark' : 'vs',
      readOnly: isReadonly.value,
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
      if (!isReadonly.value) {
        navigation.editDirty = true
      }
    })

    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
      handleSave()
    })

    resizeObserver = new ResizeObserver(() => editor?.layout())
    resizeObserver.observe(containerRef.value)
  } catch (e: unknown) {
    error.value = toErrorInfo(e)
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleKeyDown)
  resizeObserver?.disconnect()
  editor?.getModel()?.dispose()
  editor?.dispose()
  editor = null
  editorInstance.value = null
})
</script>

<template>
  <div class="flex flex-col h-full">
    <div class="flex items-center h-10 px-3 border-b gap-2 shrink-0">
      <Button variant="ghost" size="sm" @click="navigation.backToList()">
        <ArrowLeft class="size-4" />
        Back
      </Button>

      <span class="text-sm font-mono truncate flex-1 text-muted-foreground">
        {{ fileName }}
        <span v-if="navigation.editDirty && !isReadonly" class="text-foreground ml-1">*</span>
      </span>

      <Button v-if="isReadonly && canEdit" variant="outline" size="sm" @click="navigation.openEditor()">
        <Pencil class="size-4" />
        Edit
      </Button>

      <Button variant="ghost" size="sm" @click="copyAll">
        <ClipboardCopy class="size-4" />
        Copy All
      </Button>
      <Button variant="ghost" size="sm" as="a" :href="buildDownloadUrl(filePath)" target="_blank">
        <Download class="size-4" />
        Download
      </Button>
      <Button
        v-if="!isReadonly"
        size="sm"
        :disabled="!navigation.editDirty || saving"
        @click="handleSave"
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
        {{ $t('err.' + error.code, error.params ?? {}) }}
      </div>
      <div ref="containerRef" class="h-full" />
    </div>

    <EditorStatusBar
      v-if="!loading && !error"
      :editor="editorInstance"
      :language="monacoLanguage(fileName)"
      :readonly="isReadonly"
    />
  </div>
</template>
