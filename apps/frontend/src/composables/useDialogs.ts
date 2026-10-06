import { reactive } from 'vue'
import { useNavigationStore } from '@/stores/navigation'

export interface ConfirmState {
  open: boolean
  title: string
  message: string
  actionLabel: string
  danger: boolean
  hideCancel: boolean
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

export interface EditorConflictState {
  open: boolean
  resolve: ((value: 'overwrite' | 'reload' | 'cancel') => void) | null
}

export interface OperationReportItem {
  name: string
  reason: string
}

export interface OperationReportState {
  open: boolean
  title: string
  description: string
  items: OperationReportItem[]
  warningBanner: string
  highlightItem: string
  continueLabel: string
  dangerContinue: boolean
  resolve: ((value: boolean) => void) | null
}

export const REASON_LABELS: Record<string, string> = {
  blocked_extension: 'Restricted extension',
  blocked_ignored: 'Restricted path',
  blocked_invalid: 'Invalid characters',
  io_error: 'I/O error',
  'File already exists': 'File already exists',
}

export function reasonLabel(reason: string): string {
  return REASON_LABELS[reason] ?? reason
}

export const dialogState = reactive({
  confirm: {
    open: false, title: '', message: '', actionLabel: 'Confirm', danger: false, hideCancel: false, resolve: null,
  } as ConfirmState,
  prompt: {
    open: false, title: '', placeholder: '', initialValue: '', submitLabel: 'OK', selectBaseName: false, resolve: null,
  } as PromptState,
  editorConflict: {
    open: false, resolve: null,
  } as EditorConflictState,
  operationReport: {
    open: false, title: '', description: '', items: [], warningBanner: '', highlightItem: '',
    continueLabel: '', dangerContinue: false, resolve: null,
  } as OperationReportState,
})

export function confirm(
  title: string,
  message: string,
  options?: { actionLabel?: string; danger?: boolean; hideCancel?: boolean },
): Promise<boolean> {
  if (dialogState.confirm.open) return Promise.resolve(false)
  return new Promise(resolve => {
    dialogState.confirm.title = title
    dialogState.confirm.message = message
    dialogState.confirm.actionLabel = options?.actionLabel ?? 'Confirm'
    dialogState.confirm.danger = options?.danger ?? false
    dialogState.confirm.hideCancel = options?.hideCancel ?? false
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

export function showOperationReport(options: {
  title: string
  description?: string
  items: OperationReportItem[]
  continueLabel?: string
  dangerContinue?: boolean
  highlightItem?: string
  warningBanner?: string
}): Promise<boolean> {
  if (dialogState.operationReport.open) return Promise.resolve(false)
  return new Promise(resolve => {
    dialogState.operationReport.title = options.title
    dialogState.operationReport.description = options.description ?? ''
    dialogState.operationReport.items = options.items
    dialogState.operationReport.continueLabel = options.continueLabel ?? ''
    dialogState.operationReport.dangerContinue = options.dangerContinue ?? false
    dialogState.operationReport.highlightItem = options.highlightItem ?? ''
    dialogState.operationReport.warningBanner = options.warningBanner ?? ''
    dialogState.operationReport.resolve = resolve
    dialogState.operationReport.open = true
  })
}

export function showPasteConflict(files: string[]): Promise<boolean> {
  let activePath: string | null = null
  let activeFile: string | null = null
  try {
    const nav = useNavigationStore()
    if (nav.viewMode === 'editor') {
      activePath = nav.activeFilePath
      activeFile = nav.activeFile
    }
  } catch { /* guard against call before pinia init */ }

  const affectsEditor = activePath != null && (
    files.includes(activePath) ||
    (activeFile != null && files.includes(activeFile))
  )
  const highlightName = activePath && files.includes(activePath) ? activePath
    : (activeFile && files.includes(activeFile) ? activeFile : '')

  return showOperationReport({
    title: 'File Conflict',
    description: `The following ${files.length} file(s) already exist in the target directory:`,
    items: files.map(f => ({ name: f, reason: 'File already exists' })),
    continueLabel: 'Overwrite All',
    dangerContinue: true,
    highlightItem: highlightName,
    warningBanner: affectsEditor
      ? 'One of these files is currently open in the editor. Overwriting will discard your changes.'
      : '',
  })
}

export function showEditorConflict(): Promise<'overwrite' | 'reload' | 'cancel'> {
  if (dialogState.editorConflict.open) return Promise.resolve('cancel')
  return new Promise(resolve => {
    dialogState.editorConflict.resolve = resolve
    dialogState.editorConflict.open = true
  })
}
