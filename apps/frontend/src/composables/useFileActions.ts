import { toast } from 'vue-sonner'
import { apiPost } from '@/api/client'
import type { CreateResponse, DeleteResponse, RenameResponse, PasteResponse } from '@/api/types'
import { useFileListStore } from '@/stores/fileList'
import { useTreeStore } from '@/stores/tree'
import { useNavigationStore } from '@/stores/navigation'
import { useClipboardStore } from '@/stores/clipboard'
import { useUiStore } from '@/stores/ui'
import { confirm, prompt, showPasteConflict } from '@/composables/useDialogs'
import { joinPath, validateFileName } from '@/utils/path'

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

  try {
    await apiPost<CreateResponse>('create', {
      path: navigation.currentPathStr,
      name,
      type,
    })
    toast.success(`Created ${name}`)
    refreshCurrent()
  } catch (e: unknown) {
    toast.error(e instanceof Error ? e.message : 'Failed to create')
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

  try {
    const result = await apiPost<DeleteResponse>('delete', {
      path: dirPath,
      items: itemNames,
    })
    if (result.failed.length > 0) {
      toast.error(`Failed to delete: ${result.failed.map(f => f.name).join(', ')}`)
    } else {
      toast.success(`Deleted ${result.deleted} item(s)`)
    }

    const fileList = useFileListStore()
    const tree = useTreeStore()

    if (isAffectingActiveFile(dirPath, itemNames)) {
      navigation.editDirty = false
      navigation.backToList()
    }

    const navigatedAway = isAffectingCurrentPath(dirPath, itemNames)
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
    toast.error(e instanceof Error ? e.message : 'Failed to delete')
  }
}

export async function renameItem(dirPath: string, oldName: string) {
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

  try {
    await apiPost<RenameResponse>('rename', {
      path: dirPath,
      oldName,
      newName,
    })
    toast.success(`Renamed to ${newName}`)

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
    toast.error(e instanceof Error ? e.message : 'Failed to rename')
  }
}

export async function pasteItems(targetPath: string) {
  const ui = useUiStore()
  if (ui.readonly) return

  const clipboard = useClipboardStore()
  if (!clipboard.hasContent || !clipboard.mode) return

  try {
    const check = await apiPost<PasteResponse>('paste', {
      mode: clipboard.mode,
      sourcePath: clipboard.sourcePath,
      items: clipboard.items.map(i => i.name),
      targetPath,
      overwrite: false,
    })

    if (check.conflicts.length > 0 && check.completed === 0) {
      const overwrite = await showPasteConflict(check.conflicts)
      if (!overwrite) return

      const result = await apiPost<PasteResponse>('paste', {
        mode: clipboard.mode,
        sourcePath: clipboard.sourcePath,
        items: clipboard.items.map(i => i.name),
        targetPath,
        overwrite: true,
      })

      if (result.failed.length > 0) {
        toast.error(`Failed: ${result.failed.map(f => `${f.name}: ${f.error}`).join(', ')}`)
      } else {
        const verb = clipboard.mode === 'copy' ? 'Copied' : 'Moved'
        toast.success(`${verb} ${result.completed} item(s)`)
      }
    } else {
      const verb = clipboard.mode === 'copy' ? 'Copied' : 'Moved'
      if (check.failed.length > 0) {
        toast.error(`Failed: ${check.failed.map(f => `${f.name}: ${f.error}`).join(', ')}`)
      } else if (check.conflicts.length > 0) {
        toast.warning(`${verb} ${check.completed} item(s), but ${check.conflicts.length} skipped due to conflicts`)
      } else {
        toast.success(`${verb} ${check.completed} item(s)`)
      }
    }

    if (clipboard.mode === 'cut') {
      const tree = useTreeStore()
      tree.invalidate(clipboard.sourcePath)
      tree.loadChildren(clipboard.sourcePath)
      clipboard.clear()
    }

    const navigation = useNavigationStore()
    const fileList = useFileListStore()
    const tree = useTreeStore()
    fileList.fetchDir(navigation.currentPathStr)
    tree.invalidateSubtree(targetPath)
    tree.loadChildren(targetPath)
  } catch (e: unknown) {
    toast.error(e instanceof Error ? e.message : 'Paste failed')
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
  const items = Array.from(fileList.selected)
  if (items.length === 0) return
  await deleteItems(navigation.currentPathStr, items)
}

export async function renameSelected() {
  const fileList = useFileListStore()
  const navigation = useNavigationStore()
  if (fileList.selectedCount !== 1) return
  const name = Array.from(fileList.selected)[0]
  await renameItem(navigation.currentPathStr, name)
}
