import { translate } from '@/i18n'
import { useNavigationStore } from '@/stores/navigation'

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

  const { useUiStore } = await import('@/stores/ui')
  if (useUiStore().readonly) return

  const { runPaste } = await import('@/composables/usePaste')
  await runPaste({
    mode: 'cut',
    sourcePath: payload.sourcePath,
    items: payload.items,
    targetPath,
  })
}

export function startDragFromSelection(e: DragEvent) {
  const navigation = useNavigationStore()
  const curPath = navigation.currentPathStr

  // Extract bare names from absolute-path selection
  const prefix = curPath ? curPath + '/' : ''
  const items: string[] = []
  for (const p of navigation.selected) {
    if (prefix ? p.startsWith(prefix) && !p.slice(prefix.length).includes('/') : !p.includes('/')) {
      items.push(prefix ? p.slice(prefix.length) : p)
    }
  }

  if (items.length === 0) return

  setDragData(e, curPath, items)

  if (e.dataTransfer && items.length > 1) {
    const label = document.createElement('div')
    label.textContent = translate('files.drag.count', { count: items.length }, items.length)
    label.style.cssText = 'position:fixed;top:-100px;padding:4px 8px;background:#333;color:#fff;font-size:12px;border-radius:4px;'
    document.body.appendChild(label)
    e.dataTransfer.setDragImage(label, 0, 0)
    setTimeout(() => document.body.removeChild(label), 100)
  }
}
