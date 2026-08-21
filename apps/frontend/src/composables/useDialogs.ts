import { reactive } from 'vue'

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
    open: false, files: [] as string[], resolve: null,
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
  return new Promise(resolve => {
    dialogState.pasteConflict.files = files
    dialogState.pasteConflict.resolve = resolve
    dialogState.pasteConflict.open = true
  })
}

export function showEditorConflict(): Promise<'overwrite' | 'reload' | 'cancel'> {
  return new Promise(resolve => {
    dialogState.editorConflict.resolve = resolve
    dialogState.editorConflict.open = true
  })
}
