<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount, computed } from 'vue'
import type * as Monaco from 'monaco-editor'
import { toast } from 'vue-sonner'
import { useI18n } from 'vue-i18n'
import { Button } from '@/components/ui/button'
import { apiGet, apiWrite } from '@/api/client'
import { ApiError, toErrorInfo } from '@/api/client'
import type { ErrorInfo } from '@/api/client'
import type { ReadResponse } from '@/api/types'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import { loadMonaco } from '@/composables/useMonaco'
import { monacoLanguage, isEditable } from '@/composables/useFileType'
import { showEditorConflict } from '@/composables/useDialogs'
import { ArrowLeft, Save, Loader2, ClipboardCopy, Download, Pencil } from '@lucide/vue'
import { buildDownloadUrl } from '@/api/client'
import { usePreferencesStore } from '@/stores/preferences'
import EditorStatusBar from './EditorStatusBar.vue'

const navigation = useNavigationStore()
const ui = useUiStore()
const prefs = usePreferencesStore()
const { t } = useI18n()

const containerRef = ref<HTMLElement | null>(null)
const loading = ref(true)
const error = ref<ErrorInfo | null>(null)
const saving = ref(false)

const editorInstance = ref<Monaco.editor.IStandaloneCodeEditor | null>(null)
let editor: Monaco.editor.IStandaloneCodeEditor | null = null
let resizeObserver: ResizeObserver | null = null
let currentMtime = 0

const filePath = computed(() => navigation.activeFilePath ?? '')
const fileName = computed(() => navigation.activeFile ?? '')

const isReadonly = computed(() => ui.readonly || navigation.viewMode === 'preview')
const canEdit = computed(
  () => !ui.readonly && isEditable(fileName.value) && !prefs.isFilenameBlocked(fileName.value),
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

async function save() {
  if (!editor || saving.value || isReadonly.value) return

  saving.value = true
  const savedPath = filePath.value
  const savedVersion = editor.getModel()?.getAlternativeVersionId()
  try {
    const result = await apiWrite(savedPath, editor.getValue(), currentMtime)
    if (filePath.value === savedPath) {
      currentMtime = result.mtime
      if (editor.getModel()?.getAlternativeVersionId() === savedVersion) {
        navigation.editDirty = false
      }
    }
    toast.success('File saved')
  } catch (e: unknown) {
    if (e instanceof ApiError && e.authError) {
      /* handled by the global onUnauthorized handler */
    } else if (e instanceof ApiError && e.status === 409) {
      const choice = await showEditorConflict()
      if (choice === 'overwrite') {
        const owPath = filePath.value
        const owVersion = editor!.getModel()?.getAlternativeVersionId()
        try {
          const result = await apiWrite(owPath, editor!.getValue())
          if (filePath.value === owPath) {
            currentMtime = result.mtime
            if (editor!.getModel()?.getAlternativeVersionId() === owVersion) {
              navigation.editDirty = false
            }
          }
          toast.success('File saved (overwritten)')
        } catch (e2: unknown) {
          if (e2 instanceof ApiError && e2.authError) {
            /* handled by the global onUnauthorized handler */
          } else {
            const info2 = toErrorInfo(e2)
            toast.error(t(`err.${info2.code}`, (info2.params as Record<string, unknown>) ?? {}))
          }
        }
      } else if (choice === 'reload') {
        await reloadContent()
      }
    } else {
      const info = toErrorInfo(e)
      toast.error(t(`err.${info.code}`, (info.params as Record<string, unknown>) ?? {}))
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
    if (e instanceof ApiError && e.authError) {
      /* handled by maybeUnauthorized */
    } else {
      toast.error(t(`err.${toErrorInfo(e).code}`))
    }
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
      save()
    })

    resizeObserver = new ResizeObserver(() => editor?.layout())
    resizeObserver.observe(containerRef.value)

    watch(() => prefs.resolvedTheme, (t) => {
      editor?.updateOptions({ theme: t === 'dark' ? 'vs-dark' : 'vs' })
    })
    watch(() => prefs.editorFontSize, (s) => editor?.updateOptions({ fontSize: s }))
  } catch (e: unknown) {
    error.value = toErrorInfo(e)
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
