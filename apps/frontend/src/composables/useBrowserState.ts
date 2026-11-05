import { ref, computed } from 'vue'

/**
 * Reusable browsing-state logic
 *
 * Manages: selection (multi-select with Set), focus index, search query,
 * and a reset function for navigation transitions.
 */
export function useBrowserState() {
  // ── Selection (absolute paths) ──
  const selected = ref(new Set<string>())
  const focusIndex = ref(-1)
  const lastClickedPath = ref<string | null>(null)

  const selectedCount = computed(() => selected.value.size)
  const hasSelection = computed(() => selected.value.size > 0)

  function toggleSelect(absPath: string) {
    const s = new Set(selected.value)
    if (s.has(absPath)) {
      s.delete(absPath)
    } else {
      s.add(absPath)
    }
    selected.value = s
    lastClickedPath.value = absPath
  }

  function clearSelection() {
    selected.value = new Set()
    lastClickedPath.value = null
  }

  function setSelected(paths: Set<string>) {
    selected.value = paths
  }

  function pruneSelection(validPaths: Set<string>) {
    if (selected.value.size === 0) return
    const trimmed = new Set<string>()
    for (const p of selected.value) {
      if (validPaths.has(p)) trimmed.add(p)
    }
    if (trimmed.size !== selected.value.size) {
      selected.value = trimmed
    }
  }

  function moveFocus(delta: number, entryCount: number) {
    if (entryCount === 0) return
    let next = focusIndex.value + delta
    if (next < 0) next = 0
    if (next >= entryCount) next = entryCount - 1
    focusIndex.value = next
  }

  function setFocusIndex(index: number) {
    focusIndex.value = index
  }

  // ── Search ──
  const searchQuery = ref('')
  const searchRecursive = ref(false)

  // ── Reset (call on navigation transitions) ──
  function resetBrowsingState() {
    selected.value = new Set()
    focusIndex.value = -1
    lastClickedPath.value = null
    searchQuery.value = ''
    searchRecursive.value = false
  }

  return {
    // Selection state
    selected,
    focusIndex,
    lastClickedPath,
    selectedCount,
    hasSelection,

    // Selection methods
    toggleSelect,
    clearSelection,
    setSelected,
    pruneSelection,
    moveFocus,
    setFocusIndex,

    // Search state
    searchQuery,
    searchRecursive,

    // Reset
    resetBrowsingState,
  }
}
