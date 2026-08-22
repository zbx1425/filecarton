import { reactive } from 'vue'
import { useNavigationStore } from '@/stores/navigation'

export interface ConfirmState {
  open: boolean
  title: string
  message: string
  actionLabel: string
  danger: boolean
  resolve: ((value: boolean) => void) | null
}

export interface PromptState {
  open: boolean
  title: string
  placeholder: string
  initialValue: string
  submitLabel: string
  selectBaseName: boolean
  resolve: ((value: string | null) => void) | null
}

export interface PasteConflictState {
  open: boolean
  files: string[]
  activeFilePath: string | null
  resolve: ((value: boolean) => void) | null
}

export interface EditorConflictState {
  open: boolean
  resolve: ((value: 'overwrite' | 'reload' | 'cancel') => void) | null
}

export const dialogState = reactive({
  confirm: {
    open: false, title: '', message: '', actionLabel: 'Confirm', danger: false, resolve: null,
  } as ConfirmState,
  prompt: {
    open: false, title: '', placeholder: '', initialValue: '', submitLabel: 'OK', selectBaseName: false, resolve: null,
  } as PromptState,
  pasteConflict: {
    open: false, files: [] as string[], activeFilePath: null, resolve: null,
  } as PasteConflictState,
  editorConflict: {
    open: false, resolve: null,
  } as EditorConflictState,
})

export function confirm(
  title: string,
  message: string,
  options?: { actionLabel?: string; danger?: boolean },
): Promise<boolean> {
  if (dialogState.confirm.open) return Promise.resolve(false)
  return new Promise(resolve => {
    dialogState.confirm.title = title
    dialogState.confirm.message = message
    dialogState.confirm.actionLabel = options?.actionLabel ?? 'Confirm'
    dialogState.confirm.danger = options?.danger ?? false
    dialogState.confirm.resolve = resolve
    dialogState.confirm.open = true
  })
}

export function prompt(
  title: string,
  options?: {
    placeholder?: string
    initialValue?: string
    submitLabel?: string
    selectBaseName?: boolean
  },
): Promise<string | null> {
  if (dialogState.prompt.open) return Promise.resolve(null)
  return new Promise(resolve => {
    dialogState.prompt.title = title
    dialogState.prompt.placeholder = options?.placeholder ?? ''
    dialogState.prompt.initialValue = options?.initialValue ?? ''
    dialogState.prompt.submitLabel = options?.submitLabel ?? 'OK'
    dialogState.prompt.selectBaseName = options?.selectBaseName ?? false
    dialogState.prompt.resolve = resolve
    dialogState.prompt.open = true
  })
}

export function showPasteConflict(files: string[]): Promise<boolean> {
  if (dialogState.pasteConflict.open) return Promise.resolve(false)

  let activePath: string | null = null
  try {
    const nav = useNavigationStore()
    if (nav.viewMode === 'editor' && nav.activeFilePath) {
      activePath = nav.activeFilePath
    }
  } catch { /* guard against call before pinia init */ }

  return new Promise(resolve => {
    dialogState.pasteConflict.files = files
    dialogState.pasteConflict.activeFilePath = activePath
    dialogState.pasteConflict.resolve = resolve
    dialogState.pasteConflict.open = true
  })
}

export function showEditorConflict(): Promise<'overwrite' | 'reload' | 'cancel'> {
  if (dialogState.editorConflict.open) return Promise.resolve('cancel')
  return new Promise(resolve => {
    dialogState.editorConflict.resolve = resolve
    dialogState.editorConflict.open = true
  })
}
