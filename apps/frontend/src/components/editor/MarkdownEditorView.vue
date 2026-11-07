<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount, computed, nextTick } from 'vue'
import type * as Monaco from 'monaco-editor'
import { Button } from '@/components/ui/button'
import { apiGet, toErrorInfo, buildDownloadUrl } from '@/api/client'
import type { ErrorInfo } from '@/api/client'
import type { ReadResponse } from '@/api/types'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import { usePreferencesStore } from '@/stores/preferences'
import { isFilenameBlocked } from '@/utils/pathValidation'
import { loadMonaco } from '@/composables/useMonaco'
import { monacoLanguage } from '@/composables/useFileType'
import { useEditorSave } from '@/composables/useEditorSave'
import { ArrowLeft, Save, Loader2, ClipboardCopy, Download, Code, Eye, EllipsisVertical, Pencil } from '@lucide/vue'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import IconSegmentedControl from '@/components/layout/IconSegmentedControl.vue'
import EditorStatusBar from './EditorStatusBar.vue'
import MarkdownContent from '@/components/preview/MarkdownContent.vue'
import { toast } from 'vue-sonner'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
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
const fileName = computed(() => navigation.activeFileName ?? '')
const isReadonly = computed(() => ui.readonly || navigation.viewMode === 'preview')
const canEdit = computed(() => !isReadonly.value && !isFilenameBlocked(fileName.value))

const showPreview = ref(true)
const rawContent = ref('')
const previewContent = ref('')

watch(showPreview, (val) => {
  if (val) {
    previewContent.value = editor ? editor.getValue() : rawContent.value
  } else {
    nextTick(() => editor?.layout())
  }
})

async function copyAll() {
  const text = editor ? editor.getValue() : rawContent.value
  try {
    await navigator.clipboard.writeText(text)
    toast.success(t('toast.copied_clipboard'))
  } catch {
    toast.error(t('toast.copy_clipboard_failed'))
  }
}

async function handleSave() {
  if (!editor || isReadonly.value) return
  await save(editor, filePath.value)
}

function handleKeyDown(e: KeyboardEvent) {
  if (!showPreview.value || isReadonly.value) return
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
    e.preventDefault()
    handleSave()
  }
}

onMounted(async () => {
  window.addEventListener('keydown', handleKeyDown)

  try {
    const fileData = await apiGet<ReadResponse>('read', { path: filePath.value })
    setMtime(fileData.mtime)
    rawContent.value = fileData.content
    previewContent.value = fileData.content

    if (isReadonly.value) {
      return
    }

    const monaco = await loadMonaco()
    if (!containerRef.value) return

    editor = monaco.editor.create(containerRef.value, {
      value: fileData.content,
      language: monacoLanguage(fileName.value),
      theme: prefs.resolvedTheme === 'dark' ? 'vs-dark' : 'vs',
      readOnly: false,
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
      handleSave()
    })

    resizeObserver = new ResizeObserver(() => {
      if (!showPreview.value) editor?.layout()
    })
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
})
</script>

<template>
  <div class="flex flex-col h-full">
    <div v-if="ui.isMobile" class="flex items-center h-10 border-b shrink-0 px-1">
      <Button variant="ghost" size="icon-sm" class="shrink-0" :aria-label="$t('common.action.back')" @click="navigation.backToList()">
        <ArrowLeft class="size-4" />
      </Button>
      <span class="min-w-0 flex-1 truncate text-sm font-mono text-muted-foreground">
        {{ fileName }}
        <span v-if="navigation.editDirty && canEdit" class="text-foreground ml-1">*</span>
      </span>
      <IconSegmentedControl
        v-if="canEdit"
        :model-value="showPreview ? 'preview' : 'code'"
        :options="[
          { value: 'code', icon: Code, label: t('editor.mode.code') },
          { value: 'preview', icon: Eye, label: t('editor.mode.preview') },
        ]"
        @update:model-value="showPreview = $event === 'preview'"
      />
      <Button
        v-if="canEdit"
        size="icon-sm"
        class="shrink-0 ms-2"
        :disabled="!navigation.editDirty || saving"
        :aria-label="$t('common.action.save')"
        @click="handleSave"
      >
        <Loader2 v-if="saving" class="size-4 animate-spin" />
        <Save v-else class="size-4" />
      </Button>
      <DropdownMenu>
        <DropdownMenuTrigger as-child>
          <Button variant="ghost" size="icon-sm" class="shrink-0" :aria-label="$t('common.a11y.more')">
            <EllipsisVertical class="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem v-if="!canEdit && !ui.readonly && !isFilenameBlocked(fileName)" @select="navigation.openEditor()">
            <Pencil class="size-4" />
            {{ $t('common.action.edit') }}
          </DropdownMenuItem>
          <DropdownMenuItem @select="copyAll">
            <ClipboardCopy class="size-4" />
            {{ $t('common.action.copy_all') }}
          </DropdownMenuItem>
          <DropdownMenuItem as="a" :href="buildDownloadUrl(filePath)" target="_blank">
            <Download class="size-4" />
            {{ $t('common.action.download') }}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
    <div v-else class="flex items-center h-10 px-3 border-b gap-2 shrink-0">
      <Button variant="ghost" size="sm" @click="navigation.backToList()">
        <ArrowLeft class="size-4" />
        {{ $t('common.action.back') }}
      </Button>

      <span class="text-sm font-mono truncate flex-1 text-muted-foreground">
        {{ fileName }}
        <span v-if="navigation.editDirty && canEdit" class="text-foreground ml-1">*</span>
      </span>

      <template v-if="canEdit">
        <div class="flex gap-0">
          <Button
            :variant="!showPreview ? 'default' : 'ghost'"
            size="sm"
            @click="showPreview = false"
          >
            {{ $t('editor.mode.code') }}
          </Button>
          <Button
            :variant="showPreview ? 'default' : 'ghost'"
            size="sm"
            @click="showPreview = true"
          >
            {{ $t('editor.mode.preview') }}
          </Button>
        </div>
      </template>
      <template v-else-if="!ui.readonly && !isFilenameBlocked(fileName)">
        <Button variant="ghost" size="sm" @click="navigation.openEditor()">
          {{ $t('common.action.edit') }}
        </Button>
      </template>

      <Button variant="ghost" size="sm" @click="copyAll">
        <ClipboardCopy class="size-4" />
        {{ $t('common.action.copy_all') }}
      </Button>
      <Button variant="ghost" size="sm" as="a" :href="buildDownloadUrl(filePath)" target="_blank">
        <Download class="size-4" />
        {{ $t('common.action.download') }}
      </Button>
      <Button
        v-if="canEdit"
        size="sm"
        :disabled="!navigation.editDirty || saving"
        @click="handleSave"
      >
        <Loader2 v-if="saving" class="size-4 animate-spin" />
        <Save v-else class="size-4" />
        {{ $t('common.action.save') }}
      </Button>
    </div>

    <div class="flex-1 min-h-0 relative">
      <div v-if="loading" class="absolute inset-0 flex items-center justify-center">
        <Loader2 class="size-5 animate-spin text-muted-foreground" />
      </div>
      <div v-if="error" class="absolute inset-0 flex items-center justify-center text-sm text-destructive">
        {{ $t('err.' + error.code, error.params ?? {}) }}
      </div>

      <!-- Monaco editor (hidden when preview is active, not created in readonly) -->
      <div
        v-if="canEdit"
        ref="containerRef"
        class="h-full"
        :class="{ 'invisible absolute inset-0': showPreview }"
      />

      <!-- Markdown preview -->
      <div
        v-if="(showPreview || isReadonly) && !loading && !error"
        class="h-full overflow-auto"
      >
        <MarkdownContent :content="previewContent" />
      </div>
    </div>

    <EditorStatusBar
      v-if="canEdit && !loading && !error && !showPreview"
      :editor="editorInstance"
      :language="monacoLanguage(fileName)"
      :readonly="false"
    />
  </div>
</template>
