import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export interface ClipboardItem {
  name: string
  isDir: boolean
}

export const useClipboardStore = defineStore('clipboard', () => {
  const mode = ref<'copy' | 'cut' | null>(null)
  const sourcePath = ref('')
  const items = ref<ClipboardItem[]>([])

  const hasContent = computed(() => mode.value !== null && items.value.length > 0)
  const itemCount = computed(() => items.value.length)

  function copy(entries: ClipboardItem[], fromPath: string) {
    mode.value = 'copy'
    sourcePath.value = fromPath
    items.value = [...entries]
  }

  function cut(entries: ClipboardItem[], fromPath: string) {
    mode.value = 'cut'
    sourcePath.value = fromPath
    items.value = [...entries]
  }

  function clear() {
    mode.value = null
    sourcePath.value = ''
    items.value = []
  }

  function isCutItem(dirPath: string, name: string): boolean {
    if (mode.value !== 'cut') return false
    if (sourcePath.value !== dirPath) return false
    return items.value.some(i => i.name === name)
  }

  return {
    mode,
    sourcePath,
    items,
    hasContent,
    itemCount,
    copy,
    cut,
    clear,
    isCutItem,
  }
})
