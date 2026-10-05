import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiGet } from '@/api/client'
import { usePreferencesStore } from '@/stores/preferences'
import type { TreeChild, TreeNodeResponse } from '@/api/types'

export const useTreeStore = defineStore('tree', () => {
  const childrenCache = ref(new Map<string, TreeChild[]>())
  const expanded = ref(new Set<string>())
  const loading = ref(new Set<string>())
  const pendingRequests = new Map<string, Promise<TreeChild[]>>()

  async function loadChildren(path: string): Promise<TreeChild[]> {
    const cached = childrenCache.value.get(path)
    if (cached) return cached

    const pending = pendingRequests.get(path)
    if (pending) return pending

    const l = new Set(loading.value)
    l.add(path)
    loading.value = l

    const promise = apiGet<TreeNodeResponse>('tree_node', { path }).then(data => {
      const newCache = new Map(childrenCache.value)
      newCache.set(path, data.children)
      childrenCache.value = newCache
      return data.children
    }).finally(() => {
      pendingRequests.delete(path)
      const l2 = new Set(loading.value)
      l2.delete(path)
      loading.value = l2
    })

    pendingRequests.set(path, promise)
    return promise
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
      await loadChildren(path)
    }
  }

  async function expandToPath(targetPath: string[]) {
    let currentPath = ''

    for (const segment of targetPath) {
      setExpanded(currentPath, true)
      await loadChildren(currentPath)
      currentPath = currentPath ? `${currentPath}/${segment}` : segment
    }

    setExpanded(currentPath, true)
    await loadChildren(currentPath)
  }

  function invalidate(path: string) {
    const newCache = new Map(childrenCache.value)
    newCache.delete(path)
    childrenCache.value = newCache
  }

  function invalidateSubtree(path: string) {
    const newCache = new Map<string, TreeChild[]>()
    const prefix = path ? path + '/' : ''
    for (const [key, value] of childrenCache.value) {
      if (key !== path && !key.startsWith(prefix)) {
        newCache.set(key, value)
      }
    }
    childrenCache.value = newCache
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
    loadChildren,
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
