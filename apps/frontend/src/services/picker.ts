import { useUiStore } from '@/stores/ui'

export interface PickedFile {
  path: string
  name: string
  isDir: boolean
  size?: number
  mtime: number
}

function getPickerConfig() {
  return useUiStore().picker
}

export function isPickerEnabled(): boolean {
  return getPickerConfig() != null
}

export function isExclusiveMode(): boolean {
  return getPickerConfig()?.mode === 'exclusive'
}

export function isPickable(isDir: boolean): boolean {
  const cfg = getPickerConfig()
  if (!cfg) return false
  if (cfg.accept === 'any') return true
  return isDir ? cfg.accept === 'directories' : cfg.accept === 'files'
}

export function canConfirmPick(items: Array<{ isDir: boolean }>): boolean {
  const cfg = getPickerConfig()
  if (!cfg) return false
  if (items.length === 0) return false
  if (!cfg.multiple && items.length > 1) return false
  return items.every(item => isPickable(item.isDir))
}

export function confirmPick(files: PickedFile[]): void {
  if (files.length === 0) return
  window.parent.postMessage({
    type: 'filecarton:pick',
    files: files.map(f => ({
      path: f.path,
      name: f.name,
      isDir: f.isDir,
      size: f.size,
      mtime: f.mtime,
    })),
  }, '*')
}

export function sendReady(): void {
  window.parent.postMessage({ type: 'filecarton:ready' }, '*')
}
