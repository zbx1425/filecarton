import { toast } from 'vue-sonner'
import i18n from '@/i18n'
import { apiGet, apiPost, buildDownloadUrl, fetchBatchDownload } from '@/api/client'
import type { CreateResponse, DeleteResponse, RenameResponse, RemoteDownloadResponse, ChecksumResponse } from '@/api/types'
import { useFileListStore } from '@/stores/fileList'
import { useTreeStore } from '@/stores/tree'
import { useNavigationStore } from '@/stores/navigation'
import { useClipboardStore } from '@/stores/clipboard'
import { useUiStore } from '@/stores/ui'
import { confirm, prompt, showPasteConflict, showOperationReport, showPermissionsDialog, showChecksumDialog } from '@/composables/useDialogs'
import { joinPath } from '@/utils/path'
import { validateFileName, isDotFileBlocked, isFilenameBlocked } from '@/utils/pathValidation'
import { errorMessage } from '@/utils/errorMessage'
import { withAsyncTaskNotification } from '@/utils/withAsyncTaskNotification'

const t = (key: string, params?: Record<string, unknown>): string =>
  (i18n.global as any).t(key, params ?? {})

export function refreshCurrent() {
  const navigation = useNavigationStore()
  const fileList = useFileListStore()
  const tree = useTreeStore()
  const path = navigation.currentPathStr
  fileList.fetchDir(path)
  tree.invalidate(path)
  tree.loadChildren(path)
}

export async function createItem(type: 'file' | 'dir', targetPath?: string) {
  const ui = useUiStore()
  if (ui.readonly) return

  const navigation = useNavigationStore()
  const path = targetPath ?? navigation.currentPathStr
  const label = type === 'file' ? 'New File' : 'New Folder'
  const placeholder = type === 'file' ? 'filename.txt' : 'folder-name'

  const name = await prompt(label, { placeholder, submitLabel: 'Create' })
  if (!name) return

  const validationError = validateFileName(name)
  if (validationError) {
    toast.error(validationError)
    return
  }

  if (isDotFileBlocked(name)) {
    toast.error(t('toast.dotfile_blocked'))
    return
  }
  if (type === 'file' && isFilenameBlocked(name)) {
    toast.error(t('toast.filetype_restricted'))
    return
  }

  const result = await withAsyncTaskNotification(
    () => apiPost<CreateResponse>('create', { path, name, type }),
    { loading: t('toast.creating', { name }), success: t('toast.created', { name }) },
  )
  if (result) {
    const tree = useTreeStore()
    tree.invalidate(path)
    tree.loadChildren(path)
    if (navigation.currentPathStr === path) {
      const fileList = useFileListStore()
      fileList.fetchDir(path)
    }
  }
}

export async function remoteDownload(
  targetDir: string,
  url: string,
  customName?: string,
) {
  const ui = useUiStore()
  if (ui.readonly || !ui.features.remoteDownload) return

  const displayLabel = customName || 'remote file'
  let activeToastId: string | number | undefined = toast.loading(`Downloading ${displayLabel}...`)

  try {
    let result = await apiPost<RemoteDownloadResponse>('remote_download', {
      path: targetDir,
      url,
      name: customName,
      overwrite: false,
    })

    if (!result.completed && result.conflict) {
      toast.dismiss(activeToastId)
      activeToastId = undefined

      const conflictFile = result.conflict
      const conflictPath = targetDir ? joinPath(targetDir, conflictFile) : conflictFile
      const overwrite = await showPasteConflict([conflictPath])
      if (!overwrite) return

      activeToastId = toast.loading(`Downloading ${conflictFile}...`)
      result = await apiPost<RemoteDownloadResponse>('remote_download', {
        path: targetDir,
        url,
        name: conflictFile,
        overwrite: true,
      })
    }

    toast.success(`Downloaded ${result.name}`, { id: activeToastId })

    const navigation = useNavigationStore()
    const fileList = useFileListStore()
    const tree = useTreeStore()

    if (navigation.currentPathStr === targetDir) {
      fileList.fetchDir(targetDir)
    }
    tree.invalidate(targetDir)
    if (targetDir === '' || tree.isExpanded(targetDir)) {
      tree.loadChildren(targetDir)
    }
  } catch (e: unknown) {
    toast.error(errorMessage(e), activeToastId !== undefined ? { id: activeToastId } : undefined)
  }
}

function isAffectingActiveFile(dirPath: string, itemNames: string[]): boolean {
  const navigation = useNavigationStore()
  if (!navigation.activeFile) return false
  if (navigation.currentPathStr !== dirPath) return false
  return itemNames.includes(navigation.activeFile)
}

function isAffectingCurrentPath(dirPath: string, itemNames: string[]): boolean {
  const navigation = useNavigationStore()
  const currentStr = navigation.currentPathStr
  for (const name of itemNames) {
    const fullPath = joinPath(dirPath, name)
    if (currentStr === fullPath || currentStr.startsWith(fullPath + '/')) {
      return true
    }
  }
  return false
}

export async function deleteItems(dirPath: string, itemNames: string[]) {
  const ui = useUiStore()
  if (ui.readonly) return

  const navigation = useNavigationStore()
  const count = itemNames.length

  const affectsEditor = navigation.editDirty && (isAffectingActiveFile(dirPath, itemNames) || isAffectingCurrentPath(dirPath, itemNames))

  let confirmed: boolean
  if (count === 1) {
    const message = affectsEditor
      ? `"${itemNames[0]}" is currently being edited with unsaved changes. Deleting it will discard your changes. Continue?`
      : `Are you sure you want to delete "${itemNames[0]}"?`
    confirmed = await confirm('Delete', message, {
      actionLabel: 'Delete',
      danger: true,
    })
  } else {
    confirmed = await showOperationReport({
      title: 'Delete',
      description: `Are you sure you want to delete ${count} items?`,
      items: itemNames.map(n => ({ name: n, reason: '' })),
      continueLabel: 'Delete',
      dangerContinue: true,
      warningBanner: affectsEditor
        ? 'One of these items is currently being edited. Unsaved changes will be lost.'
        : '',
    })
  }
  if (!confirmed) return

  const toastId = toast.loading(t('toast.deleting', { count }))
  try {
    const result = await apiPost<DeleteResponse>('delete', {
      path: dirPath,
      items: itemNames,
    })
    if (result.failed.length > 0) {
      toast.dismiss(toastId)
      await showOperationReport({
        title: 'Delete Failed',
        description: `${result.failed.length} of ${itemNames.length} item(s) could not be deleted.`,
        items: result.failed.map(f => ({ name: f.name, reason: f.error })),
      })
    } else {
      toast.success(t('toast.deleted', { count: result.deleted }), { id: toastId })
    }

    const fileList = useFileListStore()
    const tree = useTreeStore()

    const failedNames = new Set(result.failed.map(f => f.name))
    const succeededNames = itemNames.filter(n => !failedNames.has(n))

    if (isAffectingActiveFile(dirPath, succeededNames)) {
      navigation.editDirty = false
      navigation.backToList()
    }

    const navigatedAway = isAffectingCurrentPath(dirPath, succeededNames)
    if (navigatedAway) {
      navigation.editDirty = false
      const parentSegments = dirPath ? dirPath.split('/') : []
      await navigation.navigateTo(parentSegments)
    } else {
      fileList.fetchDir(navigation.currentPathStr)
    }

    for (const name of succeededNames) {
      const itemPath = joinPath(dirPath, name)
      tree.invalidateSubtree(itemPath)
    }
    tree.invalidate(dirPath)
    tree.loadChildren(dirPath)

    if (dirPath !== navigation.currentPathStr) {
      tree.invalidate(navigation.currentPathStr)
      tree.loadChildren(navigation.currentPathStr)
    }

    fileList.clearSelection()
  } catch (e: unknown) {
    toast.error(errorMessage(e), { id: toastId })
  }
}

export async function renameItem(dirPath: string, oldName: string, isDir?: boolean) {
  const ui = useUiStore()
  if (ui.readonly) return

  const navigation = useNavigationStore()
  const affectsEditor = navigation.editDirty && (isAffectingActiveFile(dirPath, [oldName]) || isAffectingCurrentPath(dirPath, [oldName]))

  if (affectsEditor) {
    const canProceed = await confirm(
      'Unsaved Changes',
      'Renaming this item will discard unsaved changes in the editor. Continue?',
      { actionLabel: 'Continue', danger: true },
    )
    if (!canProceed) return
  }

  const newName = await prompt('Rename', {
    initialValue: oldName,
    submitLabel: 'Rename',
    selectBaseName: true,
  })
  if (!newName || newName === oldName) return

  const validationError = validateFileName(newName)
  if (validationError) {
    toast.error(validationError)
    return
  }

  if (isDotFileBlocked(newName)) {
    toast.error(t('toast.dotfile_blocked'))
    return
  }
  if (!isDir && isFilenameBlocked(newName)) {
    toast.error(t('toast.filetype_restricted'))
    return
  }

  const result = await withAsyncTaskNotification(
    () => apiPost<RenameResponse>('rename', {
      path: dirPath,
      oldName,
      newName,
    }),
    { loading: t('toast.renaming', { name: newName }), success: t('toast.renamed', { name: newName }) },
  )
  if (result) {
    if (isAffectingActiveFile(dirPath, [oldName])) {
      navigation.editDirty = false
      navigation.backToList()
    }

    if (isAffectingCurrentPath(dirPath, [oldName])) {
      navigation.editDirty = false
      const oldPrefix = joinPath(dirPath, oldName)
      const currentStr = navigation.currentPathStr
      const suffix = currentStr === oldPrefix ? '' : currentStr.slice(oldPrefix.length + 1)
      const newPath = suffix ? joinPath(joinPath(dirPath, newName), suffix) : joinPath(dirPath, newName)
      await navigation.navigateTo(newPath.split('/'))
    }

    const tree = useTreeStore()
    tree.invalidateSubtree(joinPath(dirPath, oldName))
    refreshCurrent()
    tree.invalidate(dirPath)
    tree.loadChildren(dirPath)
  }
}

export async function pasteItems(targetPath: string) {
  const ui = useUiStore()
  if (ui.readonly) return

  const clipboard = useClipboardStore()
  if (!clipboard.hasContent || !clipboard.mode) return

  const { runPaste } = await import('@/composables/usePaste')
  const items = clipboard.items.map(i => i.name)
  const totalCompleted = await runPaste({
    mode: clipboard.mode,
    sourcePath: clipboard.sourcePath,
    items,
    targetPath,
  })

  if (clipboard.mode === 'cut' && totalCompleted === items.length) {
    clipboard.clear()
  }
}

export function copySelected() {
  const ui = useUiStore()
  if (ui.readonly) return

  const fileList = useFileListStore()
  const navigation = useNavigationStore()
  const clipboard = useClipboardStore()

  const items = fileList.allEntries
    .filter(e => fileList.selected.has(e.name))
    .map(e => ({ name: e.name, isDir: e.isDir }))

  if (items.length === 0) return
  clipboard.copy(items, navigation.currentPathStr)
  toast.info(t('toast.copied_items', { count: items.length }))
}

export function cutSelected() {
  const ui = useUiStore()
  if (ui.readonly) return

  const fileList = useFileListStore()
  const navigation = useNavigationStore()
  const clipboard = useClipboardStore()

  const items = fileList.allEntries
    .filter(e => fileList.selected.has(e.name))
    .map(e => ({ name: e.name, isDir: e.isDir }))

  if (items.length === 0) return
  clipboard.cut(items, navigation.currentPathStr)
  toast.info(t('toast.cut_items', { count: items.length }))
}

export async function deleteSelected() {
  const fileList = useFileListStore()
  const navigation = useNavigationStore()
  const visible = new Set(fileList.filteredEntries.map(e => e.name))
  const items = Array.from(fileList.selected).filter(n => visible.has(n))
  if (items.length === 0) return
  await deleteItems(navigation.currentPathStr, items)
}

export async function renameSelected() {
  const fileList = useFileListStore()
  const navigation = useNavigationStore()
  if (fileList.selectedCount !== 1) return
  const name = Array.from(fileList.selected)[0]
  const entry = fileList.allEntries.find(e => e.name === name)
  await renameItem(navigation.currentPathStr, name, entry?.isDir)
}

export function canModifyItemPermissions(item: { name: string; isDir: boolean; owner?: string }): boolean {
  const ui = useUiStore()
  if (ui.readonly || !ui.features.unixPermModify) return false

  if (ui.unixPermInfo.isRoot || ui.unixPermInfo.user === 'unknown') return true
  if (item.owner !== undefined && item.owner !== ui.unixPermInfo.user) return false
  return true
}

export function canModifySelectedPermissions(): boolean {
  const ui = useUiStore()
  if (ui.readonly || !ui.features.unixPermModify) return false

  const fileList = useFileListStore()
  const visible = new Set(fileList.filteredEntries.map(e => e.name))
  const selectedNames = Array.from(fileList.selected).filter(n => visible.has(n))
  if (selectedNames.length === 0) return false

  return selectedNames.some(name => {
    const dir = fileList.getDirByName(name)
    if (dir) {
      return canModifyItemPermissions({ name: dir.name, isDir: true, owner: dir.owner })
    }
    const file = fileList.getFileByName(name)
    return canModifyItemPermissions({ name, isDir: false, owner: file?.owner })
  })
}

export function openPermissionsSelected() {
  const ui = useUiStore()
  if (ui.readonly || !ui.features.unixPermModify) return
  if (!canModifySelectedPermissions()) return

  const fileList = useFileListStore()
  const navigation = useNavigationStore()
  const visible = new Set(fileList.filteredEntries.map(e => e.name))
  const selectedNames = Array.from(fileList.selected).filter(n => visible.has(n))
  if (selectedNames.length === 0) return

  const items = selectedNames
    .map(name => {
      const dir = fileList.getDirByName(name)
      if (dir) {
        return { name: dir.name, isDir: true, mode: dir.mode, owner: dir.owner, group: dir.group }
      }
      const file = fileList.getFileByName(name)
      return {
        name,
        isDir: false,
        mode: file?.mode,
        owner: file?.owner,
        group: file?.group,
      }
    })
    .filter(item => canModifyItemPermissions(item))

  if (items.length === 0) return
  showPermissionsDialog(navigation.currentPathStr, items)
}

export async function downloadSelected() {
  const fileList = useFileListStore()
  const navigation = useNavigationStore()
  const ui = useUiStore()

  const items = Array.from(fileList.selected)
  if (items.length === 0) return

  const isSingle = items.length === 1 && !!fileList.getFileByName(items[0])
  if (isSingle) {
    const filePath = joinPath(navigation.currentPathStr, items[0])
    window.open(buildDownloadUrl(filePath), '_blank')
    return
  }

  if (!ui.features.archive) return
  await fetchBatchDownload(navigation.currentPathStr, items)
}

export async function requestChecksum(filePath: string) {
  const toastId = toast.loading(t('toast.checksum_computing'))
  try {
    const result = await apiGet<ChecksumResponse>('checksum', { path: filePath })
    toast.dismiss(toastId)
    showChecksumDialog(result)
  } catch (e: unknown) {
    toast.error(errorMessage(e), { id: toastId })
  }
}

