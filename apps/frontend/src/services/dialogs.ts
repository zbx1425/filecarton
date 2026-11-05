import { reactive, computed } from 'vue'
import { translate } from '@/i18n'
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

export interface PermissionItemInfo {
  name: string
  isDir: boolean
  mode?: number
  owner?: string
  group?: string
}

export interface PermissionsDialogState {
  open: boolean
  dirPath: string
  items: PermissionItemInfo[]
}

export interface RemoteDownloadDialogState {
  open: boolean
  targetDir: string
}

export interface ChecksumDialogState {
  open: boolean
  name: string
  size: number
  sha256: string
  sha1: string
  md5: string
}

export const dialogState = reactive({
  confirm: {
    open: false, title: '', message: '', actionLabel: translate('common.action.confirm'), danger: false, hideCancel: false, resolve: null,
  } as ConfirmState,
  prompt: {
    open: false, title: '', placeholder: '', initialValue: '', submitLabel: translate('common.action.ok'), selectBaseName: false, resolve: null,
  } as PromptState,
  editorConflict: {
    open: false, resolve: null,
  } as EditorConflictState,
  operationReport: {
    open: false, title: '', description: '', items: [], warningBanner: '', highlightItem: '',
    continueLabel: '', dangerContinue: false, resolve: null,
  } as OperationReportState,
  permissions: {
    open: false, dirPath: '', items: [],
  } as PermissionsDialogState,
  remoteDownload: {
    open: false, targetDir: '',
  } as RemoteDownloadDialogState,
  checksum: {
    open: false, name: '', size: 0, sha256: '', sha1: '', md5: '',
  } as ChecksumDialogState,
})

/**
 * Reactive computed that returns true when any service-managed dialog is open.
 * Used by useKeyboard and others to suppress shortcuts while a dialog is visible.
 */
export const isAnyDialogOpen = computed(() =>
  dialogState.confirm.open
  || dialogState.prompt.open
  || dialogState.operationReport.open
  || dialogState.editorConflict.open
  || dialogState.permissions.open
  || dialogState.remoteDownload.open
  || dialogState.checksum.open,
)

export function showPermissionsDialog(dirPath: string, items: PermissionItemInfo[]) {
  if (items.length === 0) return
  dialogState.permissions.dirPath = dirPath
  dialogState.permissions.items = items
  dialogState.permissions.open = true
}

export function showRemoteDownloadDialog(targetDir?: string) {
  let resolvedDir = targetDir
  if (resolvedDir === undefined) {
    const nav = useNavigationStore()
    resolvedDir = nav.currentPathStr
  }
  dialogState.remoteDownload.targetDir = resolvedDir
  dialogState.remoteDownload.open = true
}

export function confirm(
  title: string,
  message: string,
  options?: { actionLabel?: string; danger?: boolean; hideCancel?: boolean },
): Promise<boolean> {
  if (dialogState.confirm.open) return Promise.resolve(false)
  return new Promise(resolve => {
    dialogState.confirm.title = title
    dialogState.confirm.message = message
    dialogState.confirm.actionLabel = options?.actionLabel ?? translate('common.action.confirm')
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
    dialogState.prompt.submitLabel = options?.submitLabel ?? translate('common.action.ok')
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
  try {
    const nav = useNavigationStore()
    if (nav.viewMode === 'editor') {
      activePath = nav.activeFilePath
    }
  } catch { /* guard against call before pinia init */ }

  const affectsEditor = activePath != null && files.includes(activePath)
  const highlightName = affectsEditor ? activePath! : ''

  return showOperationReport({
    title: translate('conflict.title'),
    description: translate('conflict.description', { count: files.length }, files.length),
    items: files.map(f => ({ name: f, reason: 'already_exists' })),
    continueLabel: translate('common.action.overwrite_all'),
    dangerContinue: true,
    highlightItem: highlightName,
    warningBanner: affectsEditor
      ? translate('conflict.overwrite_editor')
      : '',
  })
}

export function showChecksumDialog(data: { name: string; size: number; sha256: string; sha1: string; md5: string }) {
  dialogState.checksum.name = data.name
  dialogState.checksum.size = data.size
  dialogState.checksum.sha256 = data.sha256
  dialogState.checksum.sha1 = data.sha1
  dialogState.checksum.md5 = data.md5
  dialogState.checksum.open = true
}

export function showEditorConflict(): Promise<'overwrite' | 'reload' | 'cancel'> {
  if (dialogState.editorConflict.open) return Promise.resolve('cancel')
  return new Promise(resolve => {
    dialogState.editorConflict.resolve = resolve
    dialogState.editorConflict.open = true
  })
}
