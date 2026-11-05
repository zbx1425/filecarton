import { defineStore } from 'pinia'
import { ref } from 'vue'
import { apiGet, toErrorInfo } from '@/api/client'
import type { ErrorInfo } from '@/api/client'
import { usePreferencesStore } from '@/stores/preferences'
import type { DirEntry, FileEntry, ListResponse, TreeChild, TreeNodeResponse } from '@/api/types'

export interface DirectoryData {
  dirs: DirEntry[]
  files: FileEntry[]
  loading: boolean
  error: ErrorInfo | null
}

export const useFileTreeCacheStore = defineStore('fileSystemCache', () => {
  // ── Directory listing cache (multi-directory, keyed by absolute path) ──
  const directoryCache = ref(new Map<string, DirectoryData>())
  const pendingDirRequests = new Map<string, { abort: AbortController; promise: Promise<void> }>()

  // ── Tree children cache (keyed by absolute path) ──
  const treeChildrenCache = ref(new Map<string, TreeChild[]>())
  const treeLoading = ref(new Set<string>())
  const pendingTreeRequests = new Map<string, Promise<TreeChild[]>>()
  const generation = new Map<string, number>()

  // ── Generation helpers ──

  function getGeneration(path: string): number {
    return generation.get(path) ?? 0
  }

  function bumpGeneration(path: string) {
    generation.set(path, getGeneration(path) + 1)
  }

  // ── Directory listing ──

  function getDirectory(path: string): DirectoryData | undefined {
    return directoryCache.value.get(path)
  }

  function getFileByName(dirPath: string, name: string): FileEntry | undefined {
    return directoryCache.value.get(dirPath)?.files.find(f => f.name === name)
  }

  function getDirByName(dirPath: string, name: string): DirEntry | undefined {
    return directoryCache.value.get(dirPath)?.dirs.find(d => d.name === name)
  }

  function updateFileEntry(dirPath: string, fileName: string, update: { size: number; mtime: number }) {
    const data = directoryCache.value.get(dirPath)
    if (!data) return
    const entry = data.files.find(f => f.name === fileName)
    if (entry) {
      entry.size = update.size
      entry.mtime = update.mtime
    }
  }

  function deriveTreeChildren(data: ListResponse): TreeChild[] {
    const dirs: TreeChild[] = data.dirs.map(d => ({
      name: d.name,
      type: 'dir' as const,
      hasChildren: d.hasChildren,
    }))
    const files: TreeChild[] = data.files.map(f => ({
      name: f.name,
      type: 'file' as const,
      hasChildren: false,
    }))
    return [...dirs, ...files]
  }

  async function fetchDirectory(path: string, opts?: { background?: boolean }): Promise<void> {
    const background = opts?.background ?? false

    const existing = pendingDirRequests.get(path)
    if (existing && !background) {
      existing.abort.abort()
    }

    const abort = new AbortController()
    const signal = abort.signal

    const current = directoryCache.value.get(path)

    if (!background) {
      // Single-slot: create a fresh Map containing only this path's entry
      const newCache = new Map<string, DirectoryData>()
      newCache.set(path, {
        dirs: current?.dirs ?? [],
        files: current?.files ?? [],
        loading: true,
        error: null,
      })
      directoryCache.value = newCache
    }

    const promise = (async () => {
      try {
        const data = await apiGet<ListResponse>('list', { path }, signal)
        if (signal.aborted) return

        const newCache = new Map<string, DirectoryData>()
        newCache.set(path, {
          dirs: data.dirs,
          files: data.files,
          loading: false,
          error: null,
        })
        directoryCache.value = newCache

        // Derive tree children from list data and update tree cache
        const newTreeCache = new Map(treeChildrenCache.value)
        newTreeCache.set(path, deriveTreeChildren(data))
        treeChildrenCache.value = newTreeCache
      } catch (e: unknown) {
        if (signal.aborted) return
        if (background) {
          // Background failure: preserve old data but record the error
          const oldData = directoryCache.value.get(path)
          if (oldData) {
            const newCache = new Map(directoryCache.value)
            newCache.set(path, { ...oldData, loading: false, error: toErrorInfo(e) })
            directoryCache.value = newCache
          }
        } else {
          const newCache = new Map<string, DirectoryData>()
          newCache.set(path, {
            dirs: [],
            files: [],
            loading: false,
            error: toErrorInfo(e),
          })
          directoryCache.value = newCache
        }
      } finally {
        if (!signal.aborted) {
          pendingDirRequests.delete(path)
        }
      }
    })()

    pendingDirRequests.set(path, { abort, promise })
    return promise
  }

  // ── Tree children ──

  function getTreeChildren(path: string): TreeChild[] | undefined {
    const raw = treeChildrenCache.value.get(path)
    if (!raw) return undefined
    const prefs = usePreferencesStore()
    if (prefs.showDotFiles) return raw
    return raw.filter(c => !c.name.startsWith('.'))
  }

  function isTreeLoading(path: string): boolean {
    return treeLoading.value.has(path)
  }

  async function fetchTreeChildren(path: string, opts?: { force?: boolean }): Promise<TreeChild[]> {
    if (!opts?.force) {
      const cached = treeChildrenCache.value.get(path)
      if (cached) return cached
    }

    const pending = pendingTreeRequests.get(path)
    if (pending) return pending

    const l = new Set(treeLoading.value)
    l.add(path)
    treeLoading.value = l

    const gen = getGeneration(path)

    const promise = apiGet<TreeNodeResponse>('tree_node', { path }).then(data => {
      if (getGeneration(path) !== gen) return data.children
      const newCache = new Map(treeChildrenCache.value)
      newCache.set(path, data.children)
      treeChildrenCache.value = newCache
      return data.children
    }).finally(() => {
      if (getGeneration(path) !== gen) return
      pendingTreeRequests.delete(path)
      const l2 = new Set(treeLoading.value)
      l2.delete(path)
      treeLoading.value = l2
    })

    pendingTreeRequests.set(path, promise)
    return promise
  }

  // ── Upload tree patching ──

  function insertTreeChild(dirPath: string, child: TreeChild): boolean {
    const cached = treeChildrenCache.value.get(dirPath)
    if (!cached) return false
    if (cached.some(c => c.name === child.name && c.type === child.type)) return false

    const updated = [...cached, child]
    updated.sort((a, b) => {
      if (a.type !== b.type) return a.type === 'dir' ? -1 : 1
      return a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' })
    })
    const newCache = new Map(treeChildrenCache.value)
    newCache.set(dirPath, updated)
    treeChildrenCache.value = newCache
    return true
  }

  /**
   * After an upload completes, locally patch the tree cache along the path
   * from targetDir down to the file's directory.
   * Returns the set of directory paths whose list data may have changed.
   */
  function patchTreeForUpload(targetDir: string, relativePath: string, fileName: string): Set<string> {
    const needsRelist = new Set<string>()

    // Build the directory segments leading to the file
    const dirSegments: string[] = []
    if (relativePath) {
      const parts = relativePath.split('/')
      // All but the last segment are directory names
      dirSegments.push(...parts.slice(0, -1))
    }

    // Walk each intermediate directory and insert missing nodes
    let currentDir = targetDir
    for (const segment of dirSegments) {
      const inserted = insertTreeChild(currentDir, {
        name: segment,
        type: 'dir',
        hasChildren: true,
      })
      if (inserted) {
        needsRelist.add(currentDir)
      }
      currentDir = currentDir ? `${currentDir}/${segment}` : segment
    }

    // Insert the file itself into the leaf directory's tree cache
    const safeName = fileName
    insertTreeChild(currentDir, {
      name: safeName,
      type: 'file',
      hasChildren: false,
    })

    // The file's directory always needs re-list (new file or overwrite)
    const fileDir = currentDir
    needsRelist.add(fileDir)

    // Parent of file's directory needs re-list (mtime of fileDir changed)
    if (fileDir.includes('/')) {
      needsRelist.add(fileDir.slice(0, fileDir.lastIndexOf('/')))
    } else if (fileDir !== '') {
      needsRelist.add('')
    }

    return needsRelist
  }

  // ── Invalidation ──

  function invalidateDirectory(path: string) {
    bumpGeneration(path)
    const newDirCache = new Map(directoryCache.value)
    newDirCache.delete(path)
    directoryCache.value = newDirCache

    const newTreeCache = new Map(treeChildrenCache.value)
    newTreeCache.delete(path)
    treeChildrenCache.value = newTreeCache

    pendingTreeRequests.delete(path)
    const dirReq = pendingDirRequests.get(path)
    if (dirReq) {
      dirReq.abort.abort()
      pendingDirRequests.delete(path)
    }
  }

  function invalidateSubtree(path: string, opts?: { keepData?: boolean }) {
    bumpGeneration(path)
    const prefix = path ? path + '/' : ''

    if (opts?.keepData) {
      // Bump generations for tree paths without deleting cached data.
      // Old data remains visible while fresh requests are in flight.
      for (const key of treeChildrenCache.value.keys()) {
        if (key === path || key.startsWith(prefix)) {
          bumpGeneration(key)
        }
      }
    } else {
      // Invalidate directory cache
      const newDirCache = new Map<string, DirectoryData>()
      for (const [key, value] of directoryCache.value) {
        if (key !== path && !key.startsWith(prefix)) {
          newDirCache.set(key, value)
        }
      }
      directoryCache.value = newDirCache

      // Invalidate tree cache
      const newTreeCache = new Map<string, TreeChild[]>()
      for (const [key, value] of treeChildrenCache.value) {
        if (key !== path && !key.startsWith(prefix)) {
          newTreeCache.set(key, value)
        } else {
          bumpGeneration(key)
        }
      }
      treeChildrenCache.value = newTreeCache
    }

    // Cancel pending requests regardless
    for (const key of pendingTreeRequests.keys()) {
      if (key === path || key.startsWith(prefix)) {
        pendingTreeRequests.delete(key)
      }
    }
    for (const [key, req] of pendingDirRequests.entries()) {
      if (key === path || key.startsWith(prefix)) {
        req.abort.abort()
        pendingDirRequests.delete(key)
      }
    }
  }

  return {
    directoryCache,
    treeChildrenCache,
    treeLoading,

    // Directory listing
    getDirectory,
    getFileByName,
    getDirByName,
    updateFileEntry,
    fetchDirectory,

    // Tree children
    getTreeChildren,
    isTreeLoading,
    fetchTreeChildren,

    // Upload tree patching
    patchTreeForUpload,

    // Invalidation
    invalidateDirectory,
    invalidateSubtree,
  }
})
