import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useFileTreeCacheStore } from '@/stores/fileTreeCache'

export const useTreeUiStore = defineStore('treePanel', () => {
  const expanded = ref(new Set<string>())
  const rootError = ref(false)

  function isExpanded(path: string): boolean {
    return expanded.value.has(path)
  }

  function setExpanded(path: string, value: boolean) {
    const next = new Set(expanded.value)
    if (value) {
      next.add(path)
    } else {
      next.delete(path)
    }
    expanded.value = next
  }

  async function toggleExpand(path: string) {
    const cache = useFileTreeCacheStore()
    if (expanded.value.has(path)) {
      setExpanded(path, false)
    } else {
      setExpanded(path, true)
      try {
        await cache.fetchTreeChildren(path)
        if (path === '') rootError.value = false
      } catch {
        setExpanded(path, false)
        if (path === '') rootError.value = true
      }
    }
  }

  async function expandToPath(targetPath: string[]) {
    const cache = useFileTreeCacheStore()
    try {
      let currentPath = ''

      for (const segment of targetPath) {
        setExpanded(currentPath, true)
        await cache.fetchTreeChildren(currentPath)
        currentPath = currentPath ? `${currentPath}/${segment}` : segment
      }

      setExpanded(currentPath, true)
      await cache.fetchTreeChildren(currentPath)
    } catch {
      // Expand as deep as possible; ignore errors from inaccessible paths
    }
  }

  function collapseSubtree(path: string) {
    const prefix = path ? path + '/' : ''
    const nextExpanded = new Set(expanded.value)
    for (const key of nextExpanded) {
      if (key.startsWith(prefix) && key !== path) {
        nextExpanded.delete(key)
      }
    }
    expanded.value = nextExpanded
  }

  function retryRoot() {
    const cache = useFileTreeCacheStore()
    rootError.value = false
    cache.invalidateDirectory('')
    setExpanded('', true)
    cache.fetchTreeChildren('').catch(() => {
      rootError.value = true
    })
  }

  return {
    expanded,
    rootError,
    isExpanded,
    setExpanded,
    toggleExpand,
    expandToPath,
    collapseSubtree,
    retryRoot,
  }
})
