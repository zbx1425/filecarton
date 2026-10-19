import { ref, computed, watch, onMounted, onBeforeUnmount, type Ref } from 'vue'
import {
  apiGet,
  toErrorInfo,
  buildArchiveDownloadUrl,
} from '@/api/client'
import type { ErrorInfo } from '@/api/client'
import type { ArchiveListResponse, ArchiveEntry } from '@/api/types'
import { usePreferencesStore } from '@/stores/preferences'
import { splitPath, parentPath, pathName } from '@/utils/path'

let activeArchiveBackHandler: ((key: 'Escape' | 'Backspace') => boolean) | null = null

export function handleArchiveBackKey(key: 'Escape' | 'Backspace'): boolean {
  return activeArchiveBackHandler ? activeArchiveBackHandler(key) : false
}

export interface ArchiveTreeItem {
  name: string
  path: string
  isDir: boolean
  size: number
  mtime: number
  hasChildren: boolean
}

export type ArchiveSortColumn = 'name' | 'size' | 'mtime'

const naturalCollator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })

export function useArchiveExplorer(
  archivePath: Ref<string>,
  archivePropName?: Ref<string | undefined>,
) {
  const preferences = usePreferencesStore()

  const loading = ref(true)
  const error = ref<ErrorInfo | null>(null)
  const data = ref<ArchiveListResponse | null>(null)

  const currentPath = ref<string[]>([])
  const viewMode = ref<'list' | 'preview'>('list')
  const activeEntry = ref<ArchiveTreeItem | null>(null)
  const selectedName = ref<string | null>(null)
  const expandedPaths = ref<Set<string>>(new Set())
  const treeVisible = ref(true)

  const searchQuery = ref('')
  const searchRecursive = ref(false)
  const sortBy = ref<ArchiveSortColumn>('name')
  const sortAsc = ref(true)

  const archiveName = computed(() => archivePropName?.value || pathName(archivePath.value))
  const currentPathStr = computed(() => currentPath.value.join('/'))
  const isAtRoot = computed(() => currentPath.value.length === 0 && !activeEntry.value)

  const nodeMap = ref<Map<string, ArchiveTreeItem>>(new Map())
  const childrenByParent = ref<Map<string, ArchiveTreeItem[]>>(new Map())

  function buildTreeFromEntries(entries: ArchiveEntry[]) {
    const nodes = new Map<string, ArchiveTreeItem>()
    const parentMap = new Map<string, Set<string>>()

    function addChildRelation(parent: string, childPath: string) {
      let set = parentMap.get(parent)
      if (!set) {
        set = new Set<string>()
        parentMap.set(parent, set)
      }
      set.add(childPath)
    }

    function ensureDir(dirPath: string, mtime = 0) {
      if (!dirPath) return
      const existing = nodes.get(dirPath)
      if (existing) {
        existing.isDir = true
        if (mtime > 0 && existing.mtime === 0) existing.mtime = mtime
        return
      }
      const slashIdx = dirPath.lastIndexOf('/')
      const parent = slashIdx >= 0 ? dirPath.slice(0, slashIdx) : ''
      const name = slashIdx >= 0 ? dirPath.slice(slashIdx + 1) : dirPath
      if (parent) ensureDir(parent, mtime)

      nodes.set(dirPath, {
        name,
        path: dirPath,
        isDir: true,
        size: 0,
        mtime,
        hasChildren: false,
      })
      addChildRelation(parent, dirPath)
    }

    for (const entry of entries) {
      const cleanPath = entry.path.replace(/\\/g, '/').replace(/^\/+|\/+$/g, '')
      if (!cleanPath) continue

      const mtime = entry.mtime ?? 0
      if (entry.isDir) {
        ensureDir(cleanPath, mtime)
        if (mtime > 0) {
          const dirNode = nodes.get(cleanPath)
          if (dirNode) dirNode.mtime = mtime
        }
      } else {
        const slashIdx = cleanPath.lastIndexOf('/')
        const parent = slashIdx >= 0 ? cleanPath.slice(0, slashIdx) : ''
        const name = slashIdx >= 0 ? cleanPath.slice(slashIdx + 1) : cleanPath
        if (parent) ensureDir(parent, mtime)

        nodes.set(cleanPath, {
          name,
          path: cleanPath,
          isDir: false,
          size: entry.size ?? 0,
          mtime,
          hasChildren: false,
        })
        addChildRelation(parent, cleanPath)
      }
    }

    const childrenMap = new Map<string, ArchiveTreeItem[]>()
    for (const [parent, childPaths] of parentMap.entries()) {
      const list: ArchiveTreeItem[] = []
      for (const cp of childPaths) {
        const n = nodes.get(cp)
        if (n) list.push(n)
      }
      list.sort((a, b) => {
        if (a.isDir !== b.isDir) return a.isDir ? -1 : 1
        return naturalCollator.compare(a.name, b.name)
      })
      childrenMap.set(parent, list)
      if (parent) {
        const pNode = nodes.get(parent)
        if (pNode) pNode.hasChildren = list.length > 0
      }
    }

    nodeMap.value = nodes
    childrenByParent.value = childrenMap
  }

  async function fetchArchiveList() {
    loading.value = true
    error.value = null
    data.value = null
    currentPath.value = []
    viewMode.value = 'list'
    activeEntry.value = null
    selectedName.value = null
    expandedPaths.value = new Set()
    searchQuery.value = ''
    searchRecursive.value = false

    try {
      const res = await apiGet<ArchiveListResponse>('archive_list', { path: archivePath.value })
      data.value = res
      buildTreeFromEntries(res.entries)
    } catch (e: unknown) {
      error.value = toErrorInfo(e)
    } finally {
      loading.value = false
    }
  }

  function onBackKey(key: 'Escape' | 'Backspace'): boolean {
    if (viewMode.value === 'preview') {
      backToArchiveList()
      return true
    }
    if (key === 'Backspace' && currentPath.value.length > 0) {
      goUp()
      return true
    }
    return false
  }

  onMounted(() => {
    activeArchiveBackHandler = onBackKey
    fetchArchiveList()
  })
  onBeforeUnmount(() => {
    if (activeArchiveBackHandler === onBackKey) {
      activeArchiveBackHandler = null
    }
  })
  watch(archivePath, fetchArchiveList)

  function isVisibleByDotfilePref(name: string): boolean {
    if (preferences.showDotFiles) return true
    return !name.startsWith('.')
  }

  function getTreeChildren(parentPathStr: string): ArchiveTreeItem[] {
    const raw = childrenByParent.value.get(parentPathStr) ?? []
    if (preferences.showDotFiles) return raw
    return raw.filter(item => !item.name.startsWith('.'))
  }

  function toggleExpand(pathStr: string) {
    const next = new Set(expandedPaths.value)
    if (next.has(pathStr)) {
      next.delete(pathStr)
    } else {
      next.add(pathStr)
    }
    expandedPaths.value = next
  }

  function expandToSegments(segments: string[]) {
    if (segments.length === 0) return
    const next = new Set(expandedPaths.value)
    let acc = ''
    for (const seg of segments) {
      acc = acc ? `${acc}/${seg}` : seg
      next.add(acc)
    }
    expandedPaths.value = next
  }

  function navigateTo(segments: string[]) {
    viewMode.value = 'list'
    activeEntry.value = null
    selectedName.value = null
    currentPath.value = segments
    searchQuery.value = ''
    searchRecursive.value = false
    expandToSegments(segments)
  }

  function navigateToSegment(index: number) {
    navigateTo(currentPath.value.slice(0, index + 1))
  }

  function goUp() {
    if (currentPath.value.length > 0) {
      navigateTo(currentPath.value.slice(0, -1))
    }
  }

  function openEntryNode(node: ArchiveTreeItem) {
    if (node.isDir) {
      navigateTo(splitPath(node.path))
    } else {
      const parent = parentPath(node.path)
      const parentSegs = parent ? splitPath(parent) : []
      currentPath.value = parentSegs
      expandToSegments(parentSegs)
      activeEntry.value = node
      viewMode.value = 'preview'
    }
  }

  function backToArchiveList() {
    viewMode.value = 'list'
    activeEntry.value = null
  }

  function downloadEntry(entryPath: string) {
    if (!data.value?.randomAccess) return
    window.open(buildArchiveDownloadUrl(archivePath.value, entryPath), '_blank')
  }

  function toggleSort(column: ArchiveSortColumn) {
    if (sortBy.value === column) {
      sortAsc.value = !sortAsc.value
    } else {
      sortBy.value = column
      sortAsc.value = true
    }
  }

  const currentChildren = computed(() => childrenByParent.value.get(currentPathStr.value) ?? [])
  const currentDirs = computed(() => currentChildren.value.filter(c => c.isDir))
  const currentFiles = computed(() => currentChildren.value.filter(c => !c.isDir))

  const sortedDirs = computed(() => {
    const arr = [...currentDirs.value]
    const dir = sortAsc.value ? 1 : -1
    if (sortBy.value === 'mtime') {
      arr.sort((a, b) => dir * (a.mtime - b.mtime) || naturalCollator.compare(a.name, b.name))
    } else {
      arr.sort((a, b) => dir * naturalCollator.compare(a.name, b.name))
    }
    return arr
  })

  const sortedFiles = computed(() => {
    const arr = [...currentFiles.value]
    const dir = sortAsc.value ? 1 : -1
    if (sortBy.value === 'name') {
      arr.sort((a, b) => dir * naturalCollator.compare(a.name, b.name))
    } else if (sortBy.value === 'size') {
      arr.sort((a, b) => dir * (a.size - b.size) || naturalCollator.compare(a.name, b.name))
    } else if (sortBy.value === 'mtime') {
      arr.sort((a, b) => dir * (a.mtime - b.mtime) || naturalCollator.compare(a.name, b.name))
    }
    return arr
  })

  const filteredDirs = computed(() => {
    let result = sortedDirs.value.filter(d => isVisibleByDotfilePref(d.name))
    const q = searchQuery.value.trim().toLowerCase()
    if (q && !searchRecursive.value) {
      result = result.filter(d => d.name.toLowerCase().includes(q))
    }
    return result
  })

  const filteredFiles = computed(() => {
    let result = sortedFiles.value.filter(f => isVisibleByDotfilePref(f.name))
    const q = searchQuery.value.trim().toLowerCase()
    if (q && !searchRecursive.value) {
      result = result.filter(f => f.name.toLowerCase().includes(q))
    }
    return result
  })

  const filteredCurrentTotalSize = computed(() =>
    filteredFiles.value.reduce((sum, f) => sum + f.size, 0),
  )

  const isActuallyEmpty = computed(
    () => currentDirs.value.length === 0 && currentFiles.value.length === 0,
  )

  const isFilteredEmpty = computed(
    () => !isActuallyEmpty.value && filteredDirs.value.length === 0 && filteredFiles.value.length === 0,
  )

  const showRecursiveSearch = computed(
    () => viewMode.value === 'list' && searchRecursive.value && searchQuery.value.trim().length > 0,
  )

  const recursiveSearchResults = computed(() => {
    if (!showRecursiveSearch.value) return []
    const q = searchQuery.value.trim().toLowerCase()
    const prefix = currentPathStr.value ? currentPathStr.value + '/' : ''
    const matches: ArchiveTreeItem[] = []

    for (const node of nodeMap.value.values()) {
      if (prefix && !node.path.startsWith(prefix)) continue
      if (!preferences.showDotFiles) {
        const segments = node.path.split('/')
        if (segments.some(s => s.startsWith('.'))) continue
      }
      if (node.name.toLowerCase().includes(q)) {
        matches.push(node)
      }
    }

    matches.sort((a, b) => {
      if (a.isDir !== b.isDir) return a.isDir ? -1 : 1
      return naturalCollator.compare(a.path, b.path)
    })
    return matches
  })

  const statusDirsCount = computed(() =>
    showRecursiveSearch.value
      ? recursiveSearchResults.value.filter(item => item.isDir).length
      : filteredDirs.value.length,
  )

  const statusFilesCount = computed(() =>
    showRecursiveSearch.value
      ? recursiveSearchResults.value.filter(item => !item.isDir).length
      : filteredFiles.value.length,
  )

  const statusTotalSize = computed(() =>
    showRecursiveSearch.value
      ? recursiveSearchResults.value.reduce((sum, item) => sum + (item.isDir ? 0 : item.size), 0)
      : filteredCurrentTotalSize.value,
  )

  return {
    loading,
    error,
    data,
    archiveName,
    currentPath,
    currentPathStr,
    isAtRoot,
    viewMode,
    activeEntry,
    selectedName,
    expandedPaths,
    treeVisible,
    searchQuery,
    searchRecursive,
    sortBy,
    sortAsc,
    filteredDirs,
    filteredFiles,
    filteredCurrentTotalSize,
    statusDirsCount,
    statusFilesCount,
    statusTotalSize,
    isActuallyEmpty,
    isFilteredEmpty,
    showRecursiveSearch,
    recursiveSearchResults,
    getTreeChildren,
    toggleExpand,
    navigateTo,
    navigateToSegment,
    goUp,
    openEntryNode,
    backToArchiveList,
    toggleSort,
    downloadEntry,
  }
}
