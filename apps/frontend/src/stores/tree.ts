import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiGet } from '@/api/client'
import { usePreferencesStore } from '@/stores/preferences'
import type { TreeChild, TreeNodeResponse } from '@/api/types'

export const useTreeStore = defineStore('tree', () => {
  const childrenCache = ref(new Map<string, TreeChild[]>())
  const expanded = ref(new Set<string>())
  const loading = ref(new Set<string>())
  const rootError = ref(false)
  const pendingRequests = new Map<string, Promise<TreeChild[]>>()
  const generation = new Map<string, number>()

  function getGeneration(path: string): number {
    return generation.get(path) ?? 0
  }

  async function loadChildren(path: string): Promise<TreeChild[]> {
    const cached = childrenCache.value.get(path)
    if (cached) return cached

    const pending = pendingRequests.get(path)
    if (pending) return pending

    const l = new Set(loading.value)
    l.add(path)
    loading.value = l

    const gen = getGeneration(path)

    const promise = apiGet<TreeNodeResponse>('tree_node', { path }).then(data => {
      if (getGeneration(path) !== gen) return data.children
      const newCache = new Map(childrenCache.value)
      newCache.set(path, data.children)
      childrenCache.value = newCache
      if (path === '') rootError.value = false
      return data.children
    }).catch(err => {
      if (path === '') rootError.value = true
      throw err
    }).finally(() => {
      if (getGeneration(path) === gen) {
        pendingRequests.delete(path)
      }
      const l2 = new Set(loading.value)
      l2.delete(path)
      loading.value = l2
    })

    pendingRequests.set(path, promise)
    return promise
  }

  function retryRoot() {
    rootError.value = false
    invalidate('')
    loadChildren('')
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
    if (expanded.value.has(path)) {
      setExpanded(path, false)
    } else {
      setExpanded(path, true)
      try {
        await loadChildren(path)
      } catch {
        setExpanded(path, false)
      }
    }
  }

  async function expandToPath(targetPath: string[]) {
    try {
      let currentPath = ''

      for (const segment of targetPath) {
        setExpanded(currentPath, true)
        await loadChildren(currentPath)
        currentPath = currentPath ? `${currentPath}/${segment}` : segment
      }

      setExpanded(currentPath, true)
      await loadChildren(currentPath)
    } catch {
      // Expand as deep as possible; ignore errors from inaccessible paths
    }
  }

  function invalidate(path: string) {
    generation.set(path, getGeneration(path) + 1)
    const newCache = new Map(childrenCache.value)
    newCache.delete(path)
    childrenCache.value = newCache
    pendingRequests.delete(path)
  }

  function invalidateSubtree(path: string) {
    generation.set(path, getGeneration(path) + 1)
    const newCache = new Map<string, TreeChild[]>()
    const prefix = path ? path + '/' : ''
    for (const [key, value] of childrenCache.value) {
      if (key !== path && !key.startsWith(prefix)) {
        newCache.set(key, value)
      } else {
        generation.set(key, getGeneration(key) + 1)
      }
    }
    childrenCache.value = newCache
    for (const key of pendingRequests.keys()) {
      if (key === path || key.startsWith(prefix)) {
        pendingRequests.delete(key)
      }
    }
  }

  function getChildren(path: string): TreeChild[] | undefined {
    const raw = childrenCache.value.get(path)
    if (!raw) return undefined
    const prefs = usePreferencesStore()
    if (prefs.showDotFiles) return raw
    return raw.filter(c => !c.name.startsWith('.'))
  }

  function isExpanded(path: string): boolean {
    return expanded.value.has(path)
  }

  function isLoading(path: string): boolean {
    return loading.value.has(path)
  }

  return {
    childrenCache,
    expanded,
    loading,
    rootError,
    loadChildren,
    retryRoot,
    setExpanded,
    toggleExpand,
    expandToPath,
    invalidate,
    invalidateSubtree,
    getChildren,
    isExpanded,
    isLoading,
  }
})
