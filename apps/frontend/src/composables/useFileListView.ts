import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useFileListStore } from '@/stores/fileList'
import { useNavigationStore } from '@/stores/navigation'
import { useClipboardStore } from '@/stores/clipboard'
import { usePreferencesStore } from '@/stores/preferences'
import { useUiStore } from '@/stores/ui'
import { refreshCurrent } from '@/composables/useFileActions'

export function useFileListView() {
  const fileList = useFileListStore()
  const navigation = useNavigationStore()
  const clipboard = useClipboardStore()
  const preferences = usePreferencesStore()
  const ui = useUiStore()

  // ── Data (refs from stores) ──

  const {
    dirs, files, loading, error,
    sortBy, sortAsc,
    selected, focusIndex,
    filteredDirs, filteredFiles, filteredEntries, allEntries,
    selectedCount, hasSelection, isAllSelected,
    filteredTotalSize,
  } = storeToRefs(fileList)

  const {
    currentPath, currentPathStr, activeFile, viewMode, editDirty,
  } = storeToRefs(navigation)

  // ── Cross-store computed ──

  const showUnixPerm = computed(() => ui.features.unixPerm && preferences.showUnixPerm)
  const canPaste = computed(() => clipboard.hasContent)

  const { effectiveTableMaxWidth } = storeToRefs(preferences)

  // ── Methods ──

  function isCutItem(name: string): boolean {
    return clipboard.isCutItem(currentPathStr.value, name)
  }

  function navigateTo(path: string[]) {
    return navigation.navigateTo(path)
  }

  function openFile(fileName: string) {
    return navigation.openFile(fileName)
  }

  function goUp() {
    const parent = currentPath.value.slice(0, -1)
    return navigation.navigateTo(parent)
  }

  function backToList() {
    return navigation.backToList()
  }

  function checkDirty() {
    return navigation.checkDirty()
  }

  // Sort
  const toggleSort = fileList.toggleSort

  // Selection
  const toggleSelect = fileList.toggleSelect
  const rangeSelect = fileList.rangeSelect
  const clearSelection = fileList.clearSelection
  const selectAll = fileList.selectAll
  const toggleSelectAll = fileList.toggleSelectAll
  const setFocusByName = fileList.setFocusByName
  const moveFocus = fileList.moveFocus

  // Query
  const getFileByName = fileList.getFileByName
  const getDirByName = fileList.getDirByName

  // Clipboard
  function copyEntries(entries: Array<{ name: string; isDir: boolean }>, fromPath: string) {
    clipboard.copy(entries, fromPath)
  }

  function cutEntries(entries: Array<{ name: string; isDir: boolean }>, fromPath: string) {
    clipboard.cut(entries, fromPath)
  }

  // Table width
  const setTableMaxWidth = preferences.setTableMaxWidth

  return {
    // File list data
    dirs, files, loading, error,
    sortBy, sortAsc,
    filteredDirs, filteredFiles, filteredEntries, allEntries,
    filteredTotalSize,

    // Selection
    selected, selectedCount, hasSelection, isAllSelected, focusIndex,

    // Navigation
    currentPath, currentPathStr, activeFile, viewMode, editDirty,

    // Cross-store derived
    showUnixPerm,
    canPaste,
    effectiveTableMaxWidth,

    // Navigation actions
    navigateTo, openFile, goUp, backToList, checkDirty,

    // Sort actions
    toggleSort,

    // Selection actions
    toggleSelect, rangeSelect, clearSelection, selectAll, toggleSelectAll,
    setFocusByName, moveFocus,

    // Query
    getFileByName, getDirByName,

    // Refresh
    refreshCurrent,

    // Clipboard
    isCutItem, copyEntries, cutEntries,

    // Table width
    setTableMaxWidth,
  }
}
