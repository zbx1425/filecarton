import { toast } from 'vue-sonner'
import { apiPost } from '@/api/client'
import type { PasteResponse } from '@/api/types'
import { useFileListStore } from '@/stores/fileList'
import { useNavigationStore } from '@/stores/navigation'
import { useTreeStore } from '@/stores/tree'

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
    const result = await apiPost<PasteResponse>('paste', {
      mode: 'cut',
      sourcePath: payload.sourcePath,
      items: payload.items,
      targetPath,
      overwrite: false,
    })

    if (result.failed.length > 0) {
      toast.error(`Move failed: ${result.failed.map(f => f.name).join(', ')}`)
    } else {
      toast.success(`Moved ${payload.items.length} item(s)`)
    }

    const navigation = useNavigationStore()
    const fileList = useFileListStore()
    const tree = useTreeStore()

    fileList.fetchDir(navigation.currentPathStr)
    tree.invalidate(payload.sourcePath)
    tree.invalidate(targetPath)
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
    requestAnimationFrame(() => document.body.removeChild(label))
  }
}
