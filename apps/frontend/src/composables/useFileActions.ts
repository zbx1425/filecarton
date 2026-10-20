import { toast } from 'vue-sonner'
import { apiPost } from '@/api/client'
import type { CreateResponse, DeleteResponse, RenameResponse, PasteResponse, RemoteDownloadResponse } from '@/api/types'
import { useFileListStore } from '@/stores/fileList'
import { useTreeStore } from '@/stores/tree'
import { useNavigationStore } from '@/stores/navigation'
import { useClipboardStore } from '@/stores/clipboard'
import { useUiStore } from '@/stores/ui'
import { confirm, prompt, showPasteConflict, showOperationReport, showPermissionsDialog } from '@/composables/useDialogs'
import { usePreferencesStore } from '@/stores/preferences'
import { joinPath, validateFileName } from '@/utils/path'
import { errorMessage } from '@/utils/errorMessage'

function refreshCurrent() {
  const navigation = useNavigationStore()
  const fileList = useFileListStore()
  const tree = useTreeStore()
  const path = navigation.currentPathStr
  fileList.fetchDir(path)
  tree.invalidate(path)
  tree.loadChildren(path)
}

export async function createItem(type: 'file' | 'dir') {
  const ui = useUiStore()
  if (ui.readonly) return

  const navigation = useNavigationStore()
  const label = type === 'file' ? 'New File' : 'New Folder'
  const placeholder = type === 'file' ? 'filename.txt' : 'folder-name'

  const name = await prompt(label, { placeholder, submitLabel: 'Create' })
  if (!name) return

  const validationError = validateFileName(name)
  if (validationError) {
    toast.error(validationError)
    return
  }

  const prefs = usePreferencesStore()
  if (prefs.isDotFileBlocked(name)) {
    toast.error('Dot files (hidden files) are not allowed')
    return
  }
  if (type === 'file' && prefs.isFilenameBlocked(name)) {
    toast.error('This file type is restricted')
    return
  }

  const toastId = toast.loading(`Creating ${name}...`)
  try {
    await apiPost<CreateResponse>('create', {
      path: navigation.currentPathStr,
      name,
      type,
    })
    toast.success(`Created ${name}`, { id: toastId })
    refreshCurrent()
  } catch (e: unknown) {
    toast.error(errorMessage(e), { id: toastId })
  }
}

export async function remoteDownload(
  targetDir: string,
  url: string,
  customName?: string,
  hintName?: string,
) {
  const ui = useUiStore()
  if (ui.readonly || !ui.features.remoteDownload) return

  const displayLabel = customName || hintName || 'remote file'
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
  let message: string
  if (affectsEditor) {
    message = count === 1
      ? `"${itemNames[0]}" is currently being edited with unsaved changes. Deleting it will discard your changes. Continue?`
      : `${count} items include a file you are editing with unsaved changes. Deleting will discard your changes. Continue?`
  } else {
    message = count === 1
      ? `Are you sure you want to delete "${itemNames[0]}"?`
      : `Are you sure you want to delete ${count} items?`
  }

  const confirmed = await confirm('Delete', message, {
    actionLabel: 'Delete',
    danger: true,
  })
  if (!confirmed) return

  const toastId = toast.loading(`Deleting ${count} item(s)...`)
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
      toast.success(`Deleted ${result.deleted} item(s)`, { id: toastId })
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

  const prefs = usePreferencesStore()
  if (prefs.isDotFileBlocked(newName)) {
    toast.error('Dot files (hidden files) are not allowed')
    return
  }
  if (!isDir && prefs.isFilenameBlocked(newName)) {
    toast.error('This file type is restricted')
    return
  }

  const toastId = toast.loading(`Renaming to ${newName}...`)
  try {
    await apiPost<RenameResponse>('rename', {
      path: dirPath,
      oldName,
      newName,
    })
    toast.success(`Renamed to ${newName}`, { id: toastId })

    if (isAffectingActiveFile(dirPath, [oldName])) {
      navigation.editDirty = false
      navigation.backToList()
    }

    if (isAffectingCurrentPath(dirPath, [oldName])) {
      navigation.editDirty = false
      const parentSegments = dirPath ? dirPath.split('/') : []
      await navigation.navigateTo(parentSegments)
    }

    refreshCurrent()
    const tree = useTreeStore()
    tree.invalidate(dirPath)
    tree.loadChildren(dirPath)
  } catch (e: unknown) {
    toast.error(errorMessage(e), { id: toastId })
  }
}

export async function pasteItems(targetPath: string) {
  const ui = useUiStore()
  if (ui.readonly) return

  const clipboard = useClipboardStore()
  if (!clipboard.hasContent || !clipboard.mode) return

  const loadingText = clipboard.mode === 'copy'
    ? `Copying ${clipboard.items.length} item(s)...`
    : `Moving ${clipboard.items.length} item(s)...`
  let activeToastId: string | number | undefined = toast.loading(loadingText)

  try {
    const check = await apiPost<PasteResponse>('paste', {
      mode: clipboard.mode,
      sourcePath: clipboard.sourcePath,
      items: clipboard.items.map(i => i.name),
      targetPath,
      overwrite: false,
    })

    const hasConflicts = check.conflicts.length > 0
    let totalCompleted = check.completed

    if (hasConflicts && check.completed === 0) {
      toast.dismiss(activeToastId)
      activeToastId = undefined

      const overwrite = await showPasteConflict(check.conflicts)
      if (!overwrite) return

      activeToastId = toast.loading(loadingText)
      const result = await apiPost<PasteResponse>('paste', {
        mode: clipboard.mode,
        sourcePath: clipboard.sourcePath,
        items: clipboard.items.map(i => i.name),
        targetPath,
        overwrite: true,
      })
      totalCompleted = result.completed

      const verb = clipboard.mode === 'copy' ? 'Copied' : 'Moved'
      if (result.failed.length > 0 && result.completed === 0) {
        toast.dismiss(activeToastId)
        activeToastId = undefined
        await showOperationReport({
          title: `${verb === 'Copied' ? 'Copy' : 'Move'} Failed`,
          description: `All items failed.`,
          items: result.failed.map(f => ({ name: f.name, reason: f.error })),
        })
      } else if (result.failed.length > 0) {
        toast.dismiss(activeToastId)
        activeToastId = undefined
        await showOperationReport({
          title: `Partial ${verb === 'Copied' ? 'Copy' : 'Move'}`,
          description: `${verb} ${result.completed} item(s), ${result.failed.length} failed.`,
          items: result.failed.map(f => ({ name: f.name, reason: f.error })),
        })
      } else {
        toast.success(`${verb} ${result.completed} item(s)`, { id: activeToastId })
      }
    } else {
      const verb = clipboard.mode === 'copy' ? 'Copied' : 'Moved'
      if (check.failed.length > 0 && check.completed === 0) {
        toast.dismiss(activeToastId)
        activeToastId = undefined
        await showOperationReport({
          title: `${verb === 'Copied' ? 'Copy' : 'Move'} Failed`,
          description: 'All items were rejected due to restrictions.',
          items: check.failed.map(f => ({ name: f.name, reason: f.error })),
        })
      } else if (check.failed.length > 0) {
        toast.dismiss(activeToastId)
        activeToastId = undefined
        await showOperationReport({
          title: `Partial ${verb === 'Copied' ? 'Copy' : 'Move'}`,
          description: `${verb} ${check.completed} item(s), ${check.failed.length} skipped.`,
          items: check.failed.map(f => ({ name: f.name, reason: f.error })),
        })
      } else {
        toast.success(`${verb} ${check.completed} item(s)`, { id: activeToastId })
      }
    }

    if (clipboard.mode === 'cut') {
      const tree = useTreeStore()
      tree.invalidate(clipboard.sourcePath)
      tree.loadChildren(clipboard.sourcePath)
      if (totalCompleted > 0) {
        clipboard.clear()
      }
    }

    const navigation = useNavigationStore()
    const fileList = useFileListStore()
    const tree = useTreeStore()
    fileList.fetchDir(navigation.currentPathStr)
    tree.invalidateSubtree(targetPath)
    tree.loadChildren(targetPath)
  } catch (e: unknown) {
    if (e instanceof Error && e.message === '__cancelled__') {
      if (activeToastId !== undefined) toast.dismiss(activeToastId)
      return
    }
    toast.error(errorMessage(e), activeToastId !== undefined ? { id: activeToastId } : undefined)
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
  toast.info(`Copied ${items.length} item(s)`)
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
  toast.info(`Cut ${items.length} item(s)`)
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

  const prefs = usePreferencesStore()
  if (!item.isDir && prefs.isFilenameBlocked(item.name)) return false

  if (ui.unixPermInfo.isRoot) return true
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

  const items = selectedNames.map(name => {
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

  showPermissionsDialog(navigation.currentPathStr, items)
}

