<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import type * as Monaco from 'monaco-editor'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { usePreferencesStore } from '@/stores/preferences'

const props = defineProps<{
  editor: Monaco.editor.IStandaloneCodeEditor | null
  language: string
}>()

const prefs = usePreferencesStore()

const cursorLine = ref(1)
const cursorCol = ref(1)
const lineNumbers = ref(prefs.editorLineNumbers)
const wordWrap = ref(prefs.editorWordWrap)
const insertSpaces = ref(true)
const tabSize = ref(prefs.editorTabSize)
const autoDetect = ref(prefs.editorAutoDetect)

let disposables: Monaco.IDisposable[] = []

watch(() => props.editor, (editor) => {
  disposables.forEach(d => d.dispose())
  disposables = []
  if (!editor) return

  disposables.push(
    editor.onDidChangeCursorPosition((e) => {
      cursorLine.value = e.position.lineNumber
      cursorCol.value = e.position.column
    }),
  )

  const model = editor.getModel()
  if (model && autoDetect.value) {
    const opts = model.getOptions()
    tabSize.value = opts.tabSize as 2 | 4
    insertSpaces.value = opts.insertSpaces
  }
}, { immediate: true })

const displayLanguage = computed(() => {
  const lang = props.language
  const map: Record<string, string> = {
    javascript: 'JavaScript',
    typescript: 'TypeScript',
    json: 'JSON',
    html: 'HTML',
    css: 'CSS',
    xml: 'XML',
    yaml: 'YAML',
    markdown: 'Markdown',
    python: 'Python',
    java: 'Java',
    cpp: 'C++',
    csharp: 'C#',
    php: 'PHP',
    ruby: 'Ruby',
    go: 'Go',
    rust: 'Rust',
    sql: 'SQL',
    shell: 'Shell',
    bat: 'Batch',
    powershell: 'PowerShell',
    lua: 'Lua',
    plaintext: 'Plain Text',
    ini: 'INI',
    properties: 'Properties',
    scss: 'SCSS',
    less: 'LESS',
  }
  return map[lang] ?? lang.charAt(0).toUpperCase() + lang.slice(1)
})

const indentLabel = computed(() => {
  return insertSpaces.value
    ? `Spaces: ${tabSize.value}`
    : `Tabs: ${tabSize.value}`
})

function toggleLineNumbers() {
  lineNumbers.value = !lineNumbers.value
  prefs.editorLineNumbers = lineNumbers.value
  props.editor?.updateOptions({ lineNumbers: lineNumbers.value ? 'on' : 'off' })
}

function toggleWordWrap() {
  wordWrap.value = !wordWrap.value
  prefs.editorWordWrap = wordWrap.value
  props.editor?.updateOptions({ wordWrap: wordWrap.value ? 'on' : 'off' })
}

function setIndentSpaces(useSpaces: boolean) {
  insertSpaces.value = useSpaces
  autoDetect.value = false
  prefs.editorAutoDetect = false
  const model = props.editor?.getModel()
  if (model) {
    model.updateOptions({ insertSpaces: useSpaces, tabSize: tabSize.value })
  }
}

function setTabSize(size: 2 | 4) {
  tabSize.value = size
  autoDetect.value = false
  prefs.editorAutoDetect = false
  prefs.editorTabSize = size
  const model = props.editor?.getModel()
  if (model) {
    model.updateOptions({ tabSize: size })
  }
  props.editor?.updateOptions({ tabSize: size })
}

function setAutoDetect(val: boolean) {
  autoDetect.value = val
  prefs.editorAutoDetect = val
  if (val && props.editor) {
    props.editor.updateOptions({ detectIndentation: true })
    props.editor.getAction('editor.action.detectIndentation')?.run()
    const model = props.editor.getModel()
    if (model) {
      const opts = model.getOptions()
      tabSize.value = opts.tabSize as 2 | 4
      insertSpaces.value = opts.insertSpaces
    }
  } else if (props.editor) {
    props.editor.updateOptions({ detectIndentation: false })
  }
}

onBeforeUnmount(() => {
  disposables.forEach(d => d.dispose())
})
</script>

<template>
  <div
    class="flex items-center h-9 px-2 text-xs select-none shrink-0 gap-0.5"
    style="background: var(--statusbar-bg); color: var(--statusbar-fg); border-top: 1px solid var(--statusbar-border)"
  >
    <span class="px-2 tabular-nums">
      Ln {{ cursorLine }}, Col {{ cursorCol }}
    </span>

    <div class="flex-1" />

    <button
      class="px-2 h-full transition-colors rounded-sm"
      :style="{ opacity: lineNumbers ? 1 : 0.5 }"
      :class="'hover:bg-[var(--statusbar-hover)]'"
      title="Toggle Line Numbers"
      @click="toggleLineNumbers"
    >
      LN
    </button>

    <button
      class="px-2 h-full transition-colors rounded-sm"
      :style="{ opacity: wordWrap ? 1 : 0.5 }"
      :class="'hover:bg-[var(--statusbar-hover)]'"
      title="Toggle Word Wrap"
      @click="toggleWordWrap"
    >
      Wrap
    </button>

    <Popover>
      <PopoverTrigger as-child>
        <button
          class="px-2 h-full transition-colors rounded-sm"
          :class="'hover:bg-[var(--statusbar-hover)]'"
        >
          {{ indentLabel }}
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" :side-offset="4" class="w-52 p-3 space-y-3">
        <div class="flex items-center justify-between">
          <label class="text-xs">Auto Detect</label>
          <Switch
            :model-value="autoDetect"
            @update:model-value="setAutoDetect"
          />
        </div>

        <Separator />

        <div class="space-y-1.5">
          <label class="text-xs text-muted-foreground">Indent Using</label>
          <div class="flex gap-1">
            <Button
              size="sm"
              :variant="insertSpaces ? 'default' : 'outline'"
              class="flex-1 h-7 text-xs"
              @click="setIndentSpaces(true)"
            >
              Spaces
            </Button>
            <Button
              size="sm"
              :variant="!insertSpaces ? 'default' : 'outline'"
              class="flex-1 h-7 text-xs"
              @click="setIndentSpaces(false)"
            >
              Tabs
            </Button>
          </div>
        </div>

        <Separator />

        <div class="space-y-1.5">
          <label class="text-xs text-muted-foreground">Tab Size</label>
          <div class="flex gap-1">
            <Button
              v-for="size in [2, 4] as const"
              :key="size"
              size="sm"
              :variant="tabSize === size ? 'default' : 'outline'"
              class="flex-1 h-7 text-xs"
              @click="setTabSize(size)"
            >
              {{ size }}
            </Button>
          </div>
        </div>
      </PopoverContent>
    </Popover>

    <span class="px-2">
      {{ displayLanguage }}
    </span>
  </div>
</template>
