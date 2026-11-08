import { toast } from 'vue-sonner'
import { translate as t } from '@/i18n'
import { apiGet, apiPost, buildDownloadUrl, fetchBatchDownload } from '@/api/client'
import type { CreateResponse, DeleteResponse, RenameResponse, RemoteDownloadResponse, ChecksumResponse } from '@/api/types'
import { useFileTreeCacheStore } from '@/stores/fileTreeCache'
import { useNavigationStore } from '@/stores/navigation'
import { useClipboardStore } from '@/stores/clipboard'
import { useUiStore } from '@/stores/ui'
import { confirm, prompt, showPasteConflict, showOperationReport, showPermissionsDialog, showChecksumDialog } from '@/services/dialogs'
import { handleMutation } from '@/services/treeInvalidation'
import { joinPath, pathName, parentPath } from '@/utils/path'
import { validateFileName, isDotFileBlocked, isFilenameBlocked } from '@/utils/pathValidation'
import { errorMessage } from '@/utils/errorMessage'
import { withAsyncTaskNotification } from '@/utils/withAsyncTaskNotification'
import { runPaste } from '@/composables/usePaste'

// ── Shared helpers ──

/** Extract bare item names from absolute-path selection set for the given directory */
function selectedNamesInDir(dirPath: string, selectedPaths: Set<string>): string[] {
  const prefix = dirPath ? dirPath + '/' : ''
  const names: string[] = []
  for (const p of selectedPaths) {
    if (prefix ? p.startsWith(prefix) && !p.slice(prefix.length).includes('/') : !p.includes('/')) {
      names.push(prefix ? p.slice(prefix.length) : p)
    }
  }
  return names
}

/** Build clipboard-compatible item list from selected names in a directory */
function getSelectedItemsFromDir(curPath: string): Array<{ name: string; isDir: boolean }> {
  const cache = useFileTreeCacheStore()
  const navigation = useNavigationStore()

  const names = selectedNamesInDir(curPath, navigation.selected)
  if (names.length === 0) return []

  const dirData = cache.getDirectory(curPath)
  const allEntries: Array<{ name: string; isDir: boolean }> = []
  if (dirData) {
    for (const d of dirData.dirs) allEntries.push({ name: d.name, isDir: true })
    for (const f of dirData.files) allEntries.push({ name: f.name, isDir: false })
  }
  const nameSet = new Set(names)
  return allEntries.filter(e => nameSet.has(e.name))
}

function isAffectingActiveFile(dirPath: string, itemNames: string[]): boolean {
  const navigation = useNavigationStore()
  if (!navigation.activeFile) return false
  for (const name of itemNames) {
    const fullPath = joinPath(dirPath, name)
    if (navigation.activeFile === fullPath) return true
  }
  return false
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

/**
 * After a mutation (delete/rename), navigate away if the affected paths
 * include the currently viewed file or directory.
 */
async function handleNavigationAfterMutation(dirPath: string, succeededNames: string[]) {
  const navigation = useNavigationStore()

  if (isAffectingActiveFile(dirPath, succeededNames)) {
    navigation.editDirty = false
    navigation.backToList()
  }

  if (isAffectingCurrentPath(dirPath, succeededNames)) {
    navigation.editDirty = false
    const parentSegments = dirPath ? dirPath.split('/') : []
    await navigation.navigateTo(parentSegments)
  }
}

// ── Public actions ──

export function refreshCurrent() {
  const path = useNavigationStore().currentPathStr
  handleMutation({ dirs: [path] })
}

export async function createItem(type: 'file' | 'dir', targetPath?: string) {
  const ui = useUiStore()
  if (ui.readonly) return

  const navigation = useNavigationStore()
  const path = targetPath ?? navigation.currentPathStr
  const label = type === 'file' ? t('files.action.new_file') : t('files.action.new_folder')
  const placeholder = type === 'file' ? t('files.create.file_placeholder') : t('files.create.folder_placeholder')

  const name = await prompt(label, { placeholder, submitLabel: t('common.action.create') })
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
    handleMutation({ dirs: [path] })
  }
}

export async function remoteDownload(
  targetDir: string,
  url: string,
  customName?: string,
) {
  const ui = useUiStore()
  if (ui.readonly || !ui.features.remoteDownload) return

  const displayLabel = customName || t('remote_download.default_name')
  let activeToastId: string | number | undefined = toast.loading(t('toast.downloading', { name: displayLabel }))

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

      activeToastId = toast.loading(t('toast.downloading', { name: conflictFile }))
      result = await apiPost<RemoteDownloadResponse>('remote_download', {
        path: targetDir,
        url,
        name: conflictFile,
        overwrite: true,
      })
    }

    toast.success(t('toast.downloaded', { name: result.name }), { id: activeToastId })

    handleMutation({ dirs: [targetDir] })
  } catch (e: unknown) {
    toast.error(errorMessage(e), activeToastId !== undefined ? { id: activeToastId } : undefined)
  }
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
      ? t('files.delete.confirm_one_dirty', { name: itemNames[0] })
      : t('files.delete.confirm_one', { name: itemNames[0] })
    confirmed = await confirm(t('common.action.delete'), message, {
      actionLabel: t('common.action.delete'),
      danger: true,
    })
  } else {
    confirmed = await showOperationReport({
      title: t('common.action.delete'),
      description: t('files.delete.confirm_many', { count }, count),
      items: itemNames.map(n => ({ name: n, reason: '' })),
      continueLabel: t('common.action.delete'),
      dangerContinue: true,
      warningBanner: affectsEditor
        ? t('files.delete.dirty_banner')
        : '',
    })
  }
  if (!confirmed) return

  const toastId = toast.loading(t('toast.deleting', { count }, count))
  try {
    const result = await apiPost<DeleteResponse>('delete', {
      path: dirPath,
      items: itemNames,
    })
    if (result.failed.length > 0) {
      toast.dismiss(toastId)
      await showOperationReport({
        title: t('files.delete.failed_title'),
        description: t('files.delete.failed_summary', { failed: result.failed.length, total: itemNames.length }, result.failed.length),
        items: result.failed.map(f => ({ name: f.name, reason: f.error })),
      })
    } else {
      toast.success(t('toast.deleted', { count: result.deleted }, result.deleted), { id: toastId })
    }

    const failedNames = new Set(result.failed.map(f => f.name))
    const succeededNames = itemNames.filter(n => !failedNames.has(n))

    await handleNavigationAfterMutation(dirPath, succeededNames)

    handleMutation({
      dirs: [dirPath],
      removed: succeededNames.map(n => joinPath(dirPath, n)),
    })

    navigation.clearSelection()
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
      t('common.dialog.unsaved_changes'),
      t('files.rename.discard_unsaved'),
      { actionLabel: t('common.action.continue'), danger: true },
    )
    if (!canProceed) return
  }

  const newName = await prompt(t('common.action.rename'), {
    initialValue: oldName,
    submitLabel: t('common.action.rename'),
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
    // Special case for rename: navigate to the renamed path if currently inside it
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

    handleMutation({
      dirs: [dirPath],
      removed: [joinPath(dirPath, oldName)],
    })
  }
}

export async function pasteItems(targetPath: string) {
  const ui = useUiStore()
  if (ui.readonly) return

  const clipboard = useClipboardStore()
  if (!clipboard.hasContent || !clipboard.mode) return

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

function clipboardAction(mode: 'copy' | 'cut') {
  const ui = useUiStore()
  if (ui.readonly) return

  const navigation = useNavigationStore()
  const clipboard = useClipboardStore()
  const curPath = navigation.currentPathStr

  const items = getSelectedItemsFromDir(curPath)
  if (items.length === 0) return

  if (mode === 'copy') {
    clipboard.copy(items, curPath)
    toast.info(t('toast.copied_items', { count: items.length }, items.length))
  } else {
    clipboard.cut(items, curPath)
    toast.info(t('toast.cut_items', { count: items.length }, items.length))
  }
}

export function copySelected() {
  clipboardAction('copy')
}

export function cutSelected() {
  clipboardAction('cut')
}

export async function deleteSelected() {
  const navigation = useNavigationStore()
  const curPath = navigation.currentPathStr
  const names = selectedNamesInDir(curPath, navigation.selected)
  if (names.length === 0) return
  await deleteItems(curPath, names)
}

export async function renameSelected() {
  const cache = useFileTreeCacheStore()
  const navigation = useNavigationStore()
  if (navigation.selectedCount !== 1) return
  const absPath = Array.from(navigation.selected)[0]
  const name = pathName(absPath)
  const dirPath = parentPath(absPath)

  const dirData = cache.getDirectory(dirPath)
  const isDir = dirData?.dirs.some(d => d.name === name)
  await renameItem(dirPath, name, isDir)
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

  const cache = useFileTreeCacheStore()
  const navigation = useNavigationStore()
  const curPath = navigation.currentPathStr
  const names = selectedNamesInDir(curPath, navigation.selected)
  if (names.length === 0) return false

  return names.some(name => {
    const dir = cache.getDirByName(curPath, name)
    if (dir) {
      return canModifyItemPermissions({ name: dir.name, isDir: true, owner: dir.owner })
    }
    const file = cache.getFileByName(curPath, name)
    return canModifyItemPermissions({ name, isDir: false, owner: file?.owner })
  })
}

export function openPermissionsSelected() {
  const ui = useUiStore()
  if (ui.readonly || !ui.features.unixPermModify) return
  if (!canModifySelectedPermissions()) return

  const cache = useFileTreeCacheStore()
  const navigation = useNavigationStore()
  const curPath = navigation.currentPathStr
  const names = selectedNamesInDir(curPath, navigation.selected)
  if (names.length === 0) return

  const items = names
    .map(name => {
      const dir = cache.getDirByName(curPath, name)
      if (dir) {
        return { name: dir.name, isDir: true, mode: dir.mode, owner: dir.owner, group: dir.group }
      }
      const file = cache.getFileByName(curPath, name)
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
  showPermissionsDialog(curPath, items)
}

export async function downloadSelected() {
  const navigation = useNavigationStore()
  const cache = useFileTreeCacheStore()
  const ui = useUiStore()
  const curPath = navigation.currentPathStr

  const names = selectedNamesInDir(curPath, navigation.selected)
  if (names.length === 0) return

  const isSingle = names.length === 1 && !!cache.getFileByName(curPath, names[0])
  if (isSingle) {
    const filePath = joinPath(curPath, names[0])
    window.open(buildDownloadUrl(filePath), '_blank')
    return
  }

  if (!ui.features.archive) return
  const toastId = toast.loading(t('toast.preparing_download'))
  try {
    await fetchBatchDownload(curPath, names)
    toast.dismiss(toastId)
  } catch (e: unknown) {
    toast.error(errorMessage(e), { id: toastId })
  }
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
