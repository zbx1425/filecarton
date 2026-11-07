import { computed, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useFileTreeCacheStore } from '@/stores/fileTreeCache'
import { useNavigationStore, checkDirty } from '@/stores/navigation'
import { useClipboardStore } from '@/stores/clipboard'
import { usePreferencesStore } from '@/stores/preferences'
import { useUiStore } from '@/stores/ui'
import { joinPath } from '@/utils/path'
import { sortItems, filterItems } from '@/utils/sorting'

export function useFileListView() {
  const cache = useFileTreeCacheStore()
  const navigation = useNavigationStore()
  const clipboard = useClipboardStore()
  const preferences = usePreferencesStore()
  const ui = useUiStore()

  // ── Raw data from cache ──

  const directoryData = computed(() => cache.getDirectory(navigation.currentPathStr))
  const dirs = computed(() => directoryData.value?.dirs ?? [])
  const files = computed(() => directoryData.value?.files ?? [])
  const loading = computed(() => directoryData.value?.loading ?? false)
  const error = computed(() => directoryData.value?.error ?? null)

  // ── Sorted ──

  const {
    sortBy, sortAsc,
    showDotFiles, effectiveTableMaxWidth,
  } = storeToRefs(preferences)

  const {
    currentPath, currentPathStr, activeFile, activeFileName, viewMode, editDirty,
    selected, focusIndex, selectedCount, hasSelection,
    searchQuery, searchRecursive,
  } = storeToRefs(navigation)

  const sortedDirs = computed(() =>
    sortItems(dirs.value, sortBy.value, sortAsc.value, (d, col) => col === 'mtime' ? d.mtime : 0),
  )

  const sortedFiles = computed(() =>
    sortItems(files.value, sortBy.value, sortAsc.value, (f, col) => col === 'size' ? f.size : f.mtime),
  )

  // ── Filtered ──

  const filteredDirs = computed(() =>
    filterItems(sortedDirs.value, showDotFiles.value, searchQuery.value, searchRecursive.value),
  )

  const filteredFiles = computed(() =>
    filterItems(sortedFiles.value, showDotFiles.value, searchQuery.value, searchRecursive.value),
  )

  // ── Entries (combined dirs + files) ──

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

  // ── Derived ──

  const filteredTotalSize = computed(() => filteredFiles.value.reduce((sum, f) => sum + f.size, 0))

  const showUnixPerm = computed(() => ui.features.unixPerm && preferences.showUnixPerm)
  const canPaste = computed(() => clipboard.hasContent)

  const isAllSelected = computed(() => {
    const entries = filteredEntries.value
    if (entries.length === 0) return false
    const curPath = currentPathStr.value
    return entries.every(e => selected.value.has(joinPath(curPath, e.name)))
  })

  // ── Selection helpers (build absolute paths) ──

  function toggleSelect(name: string) {
    const absPath = joinPath(currentPathStr.value, name)
    navigation.toggleSelect(absPath)
  }

  function rangeSelect(name: string) {
    const curPath = currentPathStr.value
    if (!navigation.lastClickedPath) {
      toggleSelect(name)
      return
    }
    const entries = filteredEntries.value
    const lastIdx = entries.findIndex(e => joinPath(curPath, e.name) === navigation.lastClickedPath)
    const curIdx = entries.findIndex(e => e.name === name)
    if (lastIdx < 0 || curIdx < 0) {
      toggleSelect(name)
      return
    }
    const start = Math.min(lastIdx, curIdx)
    const end = Math.max(lastIdx, curIdx)
    const s = new Set(selected.value)
    for (let i = start; i <= end; i++) {
      s.add(joinPath(curPath, entries[i].name))
    }
    navigation.setSelected(s)
  }

  function selectAll() {
    const curPath = currentPathStr.value
    const s = new Set<string>()
    for (const entry of filteredEntries.value) s.add(joinPath(curPath, entry.name))
    navigation.setSelected(s)
  }

  function toggleSelectAll() {
    if (isAllSelected.value) navigation.clearSelection()
    else selectAll()
  }

  function setFocusByName(name: string) {
    const idx = filteredEntries.value.findIndex(e => e.name === name)
    if (idx >= 0) navigation.setFocusIndex(idx)
  }

  function moveFocus(delta: number) {
    navigation.moveFocus(delta, filteredEntries.value.length)
  }

  function isSelected(name: string): boolean {
    return selected.value.has(joinPath(currentPathStr.value, name))
  }

  function isCutItem(name: string): boolean {
    return clipboard.isCutItem(currentPathStr.value, name)
  }

  // Same condition as ContentPanel.showRecursiveSearch.
  const showRecursiveSearch = computed(
    () => viewMode.value === 'list' && searchRecursive.value && searchQuery.value.trim().length > 0,
  )

  // ── Watch: prune selection and adjust focus when filtered entries change ──
  // This composable is created once for the session. Skip while the list is not
  // on screen, or the watch would edit selection during preview and recursive search.

  watch(filteredEntries, (entries) => {
    if (viewMode.value !== 'list' || showRecursiveSearch.value) return
    const len = entries.length
    if (len === 0) {
      navigation.setFocusIndex(-1)
    } else if (focusIndex.value >= len) {
      navigation.setFocusIndex(len - 1)
    }
    if (selected.value.size > 0) {
      const curPath = currentPathStr.value
      const validPaths = new Set(entries.map(e => joinPath(curPath, e.name)))
      navigation.pruneSelection(validPaths)
    }
  })

  watch(showRecursiveSearch, (active) => {
    if (active) navigation.clearSelection()
  })

  // ── Query helpers ──

  function getFileByName(name: string) {
    return cache.getFileByName(currentPathStr.value, name)
  }

  function getDirByName(name: string) {
    return cache.getDirByName(currentPathStr.value, name)
  }

  // ── Pass-through actions ──

  function navigateTo(path: string[]) {
    return navigation.navigateTo(path)
  }

  function openFile(fileName: string) {
    const filePath = joinPath(currentPathStr.value, fileName)
    return navigation.openFile(filePath)
  }

  function goUp() {
    const parent = currentPath.value.slice(0, -1)
    return navigation.navigateTo(parent)
  }

  function backToList() {
    return navigation.backToList()
  }

  function checkDirtyState() {
    return checkDirty(navigation.editDirty)
  }

  function copyEntries(entries: Array<{ name: string; isDir: boolean }>, fromPath: string) {
    clipboard.copy(entries, fromPath)
  }

  function cutEntries(entries: Array<{ name: string; isDir: boolean }>, fromPath: string) {
    clipboard.cut(entries, fromPath)
  }

  return {
    // File list data
    dirs, files, loading, error,
    sortBy, sortAsc,
    filteredDirs, filteredFiles, filteredEntries, allEntries,
    filteredTotalSize,

    // Selection
    selected, selectedCount, hasSelection, isAllSelected, focusIndex,
    isSelected,

    // Navigation
    currentPath, currentPathStr, activeFile, activeFileName, viewMode, editDirty,
    searchQuery, searchRecursive,
    showRecursiveSearch,

    // Cross-store derived
    showUnixPerm,
    canPaste,
    effectiveTableMaxWidth,

    // Navigation actions
    navigateTo, openFile, goUp, backToList, checkDirty: checkDirtyState,

    // Sort actions
    toggleSort: preferences.toggleSort,

    // Selection actions
    toggleSelect, rangeSelect, selectAll, toggleSelectAll,
    clearSelection: navigation.clearSelection,
    setFocusByName, moveFocus,

    // Query
    getFileByName, getDirByName,

    // Refresh (placeholder — will be wired in handleMutation)
    refreshCurrent: () => {
      cache.fetchDirectory(navigation.currentPathStr)
    },

    // Clipboard
    isCutItem, copyEntries, cutEntries,

    // Table width
    setTableMaxWidth: preferences.setTableMaxWidth,
  }
}
