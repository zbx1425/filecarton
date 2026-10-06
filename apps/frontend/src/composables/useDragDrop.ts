import { toast } from 'vue-sonner'
import { apiPost } from '@/api/client'
import type { PasteResponse } from '@/api/types'
import { useFileListStore } from '@/stores/fileList'
import { useNavigationStore } from '@/stores/navigation'
import { useTreeStore } from '@/stores/tree'
import { showPasteConflict, showOperationReport } from '@/composables/useDialogs'

const MIME_TYPE = 'application/filecarton'

export interface DragPayload {
  sourcePath: string
  items: string[]
}

export function setDragData(
  e: DragEvent,
  sourcePath: string,
  items: string[],
) {
  if (!e.dataTransfer) return
  const payload: DragPayload = { sourcePath, items }
  e.dataTransfer.setData(MIME_TYPE, JSON.stringify(payload))
  e.dataTransfer.effectAllowed = 'move'
}

export function isInternalDrag(e: DragEvent): boolean {
  return e.dataTransfer?.types.includes(MIME_TYPE) === true
}

export function getDragPayload(e: DragEvent): DragPayload | null {
  try {
    const raw = e.dataTransfer?.getData(MIME_TYPE)
    if (!raw) return null
    return JSON.parse(raw)
  } catch {
    return null
  }
}

export async function handleDrop(e: DragEvent, targetPath: string) {
  e.preventDefault()
  const payload = getDragPayload(e)
  if (!payload) return
  if (payload.sourcePath === targetPath) return

  try {
    const check = await apiPost<PasteResponse>('paste', {
      mode: 'cut',
      sourcePath: payload.sourcePath,
      items: payload.items,
      targetPath,
      overwrite: false,
    })

    const hasConflicts = check.conflicts.length > 0
    const hasFailed = check.failed.length > 0

    if ((hasConflicts || hasFailed) && check.completed === 0) {
      const totalItems = payload.items.length
      const passCount = totalItems - check.failed.length

      let useOverwrite = false

      if (hasFailed && !hasConflicts) {
        if (passCount <= 0) {
          await showOperationReport({
            title: 'Move Failed',
            description: 'All items were rejected due to restrictions.',
            items: check.failed.map(f => ({ name: f.name, reason: f.error })),
          })
          return
        }
        const proceed = await showOperationReport({
          title: 'Items Restricted',
          description: `${check.failed.length} item(s) will be skipped. Continue with the remaining ${passCount} item(s)?`,
          items: check.failed.map(f => ({ name: f.name, reason: f.error })),
          continueLabel: `Continue (${passCount})`,
        })
        if (!proceed) return
      } else if (hasConflicts) {
        if (hasFailed) {
          const ok = await showOperationReport({
            title: 'Items Restricted',
            description: `${check.failed.length} item(s) will be skipped due to restrictions.`,
            items: check.failed.map(f => ({ name: f.name, reason: f.error })),
            continueLabel: 'Continue',
          })
          if (!ok) return
        }
        const overwrite = await showPasteConflict(check.conflicts)
        if (!overwrite) return
        useOverwrite = true
      }

      const result = await apiPost<PasteResponse>('paste', {
        mode: 'cut',
        sourcePath: payload.sourcePath,
        items: payload.items,
        targetPath,
        overwrite: useOverwrite,
      })

      if (result.failed.length > 0 && result.completed === 0) {
        await showOperationReport({
          title: 'Move Failed',
          description: 'All items failed.',
          items: result.failed.map(f => ({ name: f.name, reason: f.error })),
        })
      } else if (result.failed.length > 0) {
        await showOperationReport({
          title: 'Move Partial',
          description: `Moved ${result.completed} item(s), ${result.failed.length} failed.`,
          items: result.failed.map(f => ({ name: f.name, reason: f.error })),
        })
      } else {
        toast.success(`Moved ${result.completed} item(s)`)
      }
    } else {
      if (check.failed.length > 0) {
        await showOperationReport({
          title: check.completed > 0 ? 'Move Partial' : 'Move Failed',
          description: check.completed > 0
            ? `Moved ${check.completed} item(s), ${check.failed.length} skipped.`
            : `${check.failed.length} item(s) could not be moved.`,
          items: check.failed.map(f => ({ name: f.name, reason: f.error })),
        })
      } else if (check.conflicts.length > 0) {
        toast.warning(`Moved ${check.completed} item(s), but ${check.conflicts.length} skipped due to conflicts`)
      } else {
        toast.success(`Moved ${check.completed} item(s)`)
      }
    }

    const navigation = useNavigationStore()
    const fileList = useFileListStore()
    const tree = useTreeStore()

    fileList.fetchDir(navigation.currentPathStr)
    tree.invalidateSubtree(payload.sourcePath)
    tree.invalidateSubtree(targetPath)
    tree.loadChildren(payload.sourcePath)
    tree.loadChildren(targetPath)
  } catch (e: unknown) {
    toast.error(e instanceof Error ? e.message : 'Move failed')
  }
}

export function startDragFromSelection(e: DragEvent) {
  const fileList = useFileListStore()
  const navigation = useNavigationStore()

  const items = fileList.hasSelection
    ? Array.from(fileList.selected)
    : []

  if (items.length === 0) return

  setDragData(e, navigation.currentPathStr, items)

  if (e.dataTransfer && items.length > 1) {
    const label = document.createElement('div')
    label.textContent = `${items.length} items`
    label.style.cssText = 'position:fixed;top:-100px;padding:4px 8px;background:#333;color:#fff;font-size:12px;border-radius:4px;'
    document.body.appendChild(label)
    e.dataTransfer.setDragImage(label, 0, 0)
    setTimeout(() => document.body.removeChild(label), 100)
  }
}
