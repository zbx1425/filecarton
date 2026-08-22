import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiGet } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import type { DirEntry, FileEntry, ListResponse } from '@/api/types'

type SortColumn = 'name' | 'size' | 'mtime'

const SORT_STORAGE_KEY = 'filecarton_sort'

const naturalCollator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })

function loadSortPreference(): { sortBy: SortColumn; sortAsc: boolean } {
  try {
    const raw = localStorage.getItem(SORT_STORAGE_KEY)
    if (raw) {
      const parsed = JSON.parse(raw)
      if (parsed.sortBy && typeof parsed.sortAsc === 'boolean') return parsed
    }
  } catch { /* ignore */ }
  return { sortBy: 'name', sortAsc: true }
}

export const useFileListStore = defineStore('fileList', () => {
  const dirs = ref<DirEntry[]>([])
  const files = ref<FileEntry[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  let fetchAbort: AbortController | null = null

  const savedSort = loadSortPreference()
  const sortBy = ref<SortColumn>(savedSort.sortBy)
  const sortAsc = ref(savedSort.sortAsc)

  const selected = ref(new Set<string>())
  const focusIndex = ref(-1)
  const lastClickedName = ref<string | null>(null)

  const sortedDirs = computed(() => {
    const arr = [...dirs.value]
    const dir = sortAsc.value ? 1 : -1
    if (sortBy.value === 'name') {
      arr.sort((a, b) => dir * naturalCollator.compare(a.name, b.name))
    } else if (sortBy.value === 'mtime') {
      arr.sort((a, b) => dir * (a.mtime - b.mtime))
    } else {
      arr.sort((a, b) => dir * naturalCollator.compare(a.name, b.name))
    }
    return arr
  })

  const sortedFiles = computed(() => {
    const arr = [...files.value]
    const dir = sortAsc.value ? 1 : -1
    if (sortBy.value === 'name') {
      arr.sort((a, b) => dir * naturalCollator.compare(a.name, b.name))
    } else if (sortBy.value === 'size') {
      arr.sort((a, b) => dir * (a.size - b.size))
    } else if (sortBy.value === 'mtime') {
      arr.sort((a, b) => dir * (a.mtime - b.mtime))
    }
    return arr
  })

  const filteredDirs = computed(() => {
    const ui = useUiStore()
    const q = ui.searchQuery.trim().toLowerCase()
    if (!q || ui.searchRecursive) return sortedDirs.value
    return sortedDirs.value.filter(d => d.name.toLowerCase().includes(q))
  })

  const filteredFiles = computed(() => {
    const ui = useUiStore()
    const q = ui.searchQuery.trim().toLowerCase()
    if (!q || ui.searchRecursive) return sortedFiles.value
    return sortedFiles.value.filter(f => f.name.toLowerCase().includes(q))
  })

  const allEntries = computed(() => {
    const result: Array<{ name: string; isDir: boolean }> = []
    for (const d of sortedDirs.value) result.push({ name: d.name, isDir: true })
    for (const f of sortedFiles.value) result.push({ name: f.name, isDir: false })
    return result
  })

  const filteredEntries = computed(() => {
    const result: Array<{ name: string; isDir: boolean }> = []
    for (const d of filteredDirs.value) result.push({ name: d.name, isDir: true })
    for (const f of filteredFiles.value) result.push({ name: f.name, isDir: false })
    return result
  })

  const selectedCount = computed(() => selected.value.size)
  const hasSelection = computed(() => selected.value.size > 0)
  const totalSize = computed(() => files.value.reduce((sum, f) => sum + f.size, 0))

  const isAllSelected = computed(() => {
    const entries = filteredEntries.value
    return entries.length > 0 && entries.every(e => selected.value.has(e.name))
  })

  async function fetchDir(path: string) {
    if (fetchAbort) fetchAbort.abort()
    fetchAbort = new AbortController()
    const signal = fetchAbort.signal

    loading.value = true
    error.value = null
    selected.value = new Set()
    focusIndex.value = -1
    lastClickedName.value = null
    try {
      const data = await apiGet<ListResponse>('list', { path }, signal)
      if (signal.aborted) return
      dirs.value = data.dirs
      files.value = data.files
    } catch (e: unknown) {
      if (signal.aborted) return
      error.value = e instanceof Error ? e.message : 'Failed to load directory'
      dirs.value = []
      files.value = []
    } finally {
      if (!signal.aborted) loading.value = false
    }
  }

  function toggleSort(column: SortColumn) {
    if (sortBy.value === column) {
      sortAsc.value = !sortAsc.value
    } else {
      sortBy.value = column
      sortAsc.value = true
    }
    localStorage.setItem(SORT_STORAGE_KEY, JSON.stringify({ sortBy: sortBy.value, sortAsc: sortAsc.value }))
  }

  function toggleSelect(name: string) {
    const s = new Set(selected.value)
    if (s.has(name)) {
      s.delete(name)
    } else {
      s.add(name)
    }
    selected.value = s
    lastClickedName.value = name
  }

  function selectAll() {
    const s = new Set<string>()
    for (const entry of filteredEntries.value) s.add(entry.name)
    selected.value = s
  }

  function clearSelection() {
    selected.value = new Set()
    lastClickedName.value = null
  }

  function toggleSelectAll() {
    if (isAllSelected.value) clearSelection()
    else selectAll()
  }

  function rangeSelect(name: string) {
    if (!lastClickedName.value) {
      toggleSelect(name)
      return
    }
    const entries = filteredEntries.value
    const lastIdx = entries.findIndex(e => e.name === lastClickedName.value)
    const curIdx = entries.findIndex(e => e.name === name)
    if (lastIdx < 0 || curIdx < 0) {
      toggleSelect(name)
      return
    }
    const start = Math.min(lastIdx, curIdx)
    const end = Math.max(lastIdx, curIdx)
    const s = new Set(selected.value)
    for (let i = start; i <= end; i++) {
      s.add(entries[i].name)
    }
    selected.value = s
  }

  function moveFocus(delta: number) {
    const entries = filteredEntries.value
    if (entries.length === 0) return
    let next = focusIndex.value + delta
    if (next < 0) next = 0
    if (next >= entries.length) next = entries.length - 1
    focusIndex.value = next
  }

  function getFileByName(name: string): FileEntry | undefined {
    return files.value.find(f => f.name === name)
  }

  function getDirByName(name: string): DirEntry | undefined {
    return dirs.value.find(d => d.name === name)
  }

  return {
    dirs,
    files,
    loading,
    error,
    sortBy,
    sortAsc,
    selected,
    focusIndex,
    sortedDirs,
    sortedFiles,
    filteredDirs,
    filteredFiles,
    allEntries,
    filteredEntries,
    selectedCount,
    hasSelection,
    totalSize,
    isAllSelected,
    fetchDir,
    toggleSort,
    toggleSelect,
    selectAll,
    toggleSelectAll,
    clearSelection,
    rangeSelect,
    moveFocus,
    getFileByName,
    getDirByName,
  }
})
