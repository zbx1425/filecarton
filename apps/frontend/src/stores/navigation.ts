import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import { EDITABLE_EXTENSIONS } from '@/utils/constants'
import { pathExtension, pathName } from '@/utils/path'
import { confirm } from '@/composables/useDialogs'
import { useUiStore } from '@/stores/ui'
import { isFilenameBlocked } from '@/utils/pathValidation'

export const useNavigationStore = defineStore('navigation', () => {
  const currentPath = ref<string[]>([])
  const viewMode = ref<'list' | 'preview' | 'editor'>('list')
  const activeFile = ref<string | null>(null) // absolute path: "docs/readme.md"
  const editDirty = ref(false)

  // ── Selection (absolute paths) ──
  const selected = ref(new Set<string>())
  const focusIndex = ref(-1)
  const lastClickedPath = ref<string | null>(null)

  // ── Search (migrated from ui store) ──
  const searchQuery = ref('')
  const searchRecursive = ref(false)

  // ── Computed ──
  const currentPathStr = computed(() => currentPath.value.join('/'))

  const activeFileName = computed(() => {
    if (!activeFile.value) return null
    return pathName(activeFile.value)
  })

  const activeFilePath = computed(() => activeFile.value)

  const selectedCount = computed(() => selected.value.size)
  const hasSelection = computed(() => selected.value.size > 0)

  // ── Dirty check ──

  let suppressHashSync = false

  async function checkDirty(): Promise<boolean> {
    if (!editDirty.value) return true
    const leave = await confirm('Unsaved Changes', 'You have unsaved changes. Leave without saving?', {
      actionLabel: 'Leave',
      danger: true,
    })
    return leave
  }

  // ── Navigation ──

  async function navigateTo(path: string[]): Promise<boolean> {
    if (viewMode.value === 'editor' && editDirty.value) {
      const canLeave = await checkDirty()
      if (!canLeave) return false
    }
    viewMode.value = 'list'
    activeFile.value = null
    editDirty.value = false
    currentPath.value = path
    selected.value = new Set()
    focusIndex.value = -1
    lastClickedPath.value = null
    searchQuery.value = ''
    searchRecursive.value = false
    return true
  }

  async function openFile(filePath: string): Promise<boolean> {
    if (viewMode.value === 'editor' && editDirty.value) {
      const canLeave = await checkDirty()
      if (!canLeave) return false
    }
    activeFile.value = filePath
    const fileName = pathName(filePath)
    const ext = pathExtension(fileName)
    const editable = EDITABLE_EXTENSIONS.has(ext)
      || fileName.toLowerCase() === 'makefile' || fileName.toLowerCase() === 'dockerfile'
    const ui = useUiStore()
    const blocked = isFilenameBlocked(fileName)
    viewMode.value = (editable && !ui.readonly && !blocked) ? 'editor' : 'preview'
    editDirty.value = false
    return true
  }

  function openEditor() {
    viewMode.value = 'editor'
  }

  async function backToList(): Promise<boolean> {
    if (viewMode.value === 'editor' && editDirty.value) {
      const canLeave = await checkDirty()
      if (!canLeave) return false
    }
    viewMode.value = 'list'
    activeFile.value = null
    editDirty.value = false
    return true
  }

  // ── Selection (absolute paths) ──

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

  /** Prune selection to only contain paths still visible in a given entry list */
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

  // ── Hash sync ──

  function syncToHash() {
    if (suppressHashSync) return
    const encodedSegments = currentPath.value.map(s => encodeURIComponent(s))
    let hash = '#/' + encodedSegments.join('/')
    if (activeFile.value) {
      const fileName = pathName(activeFile.value)
      hash += (currentPath.value.length > 0 ? '/' : '') + encodeURIComponent(fileName)
      if (viewMode.value === 'preview' || viewMode.value === 'editor') {
        hash += '?view=' + viewMode.value
      }
    }
    if (location.hash !== hash) {
      history.replaceState(null, '', hash)
    }
  }

  async function syncFromHash() {
    const hash = location.hash
    if (!hash || hash === '#' || hash === '#/') {
      suppressHashSync = true
      try {
        await navigateTo([])
      } finally {
        suppressHashSync = false
      }
      return
    }

    const raw = hash.slice(1)
    const qIndex = raw.indexOf('?')
    const pathPart = qIndex >= 0 ? raw.slice(0, qIndex) : raw
    const queryPart = qIndex >= 0 ? raw.slice(qIndex + 1) : ''

    const segments = pathPart.split('/').filter(Boolean).map(s => {
      try { return decodeURIComponent(s) } catch { return s }
    })

    const params = new URLSearchParams(queryPart)
    const view = params.get('view')

    if (view === 'preview' || view === 'editor') {
      const fileName = segments.pop()

      if (viewMode.value === 'editor' && editDirty.value) {
        const canLeave = await checkDirty()
        if (!canLeave) {
          syncToHash()
          return
        }
      }

      const filePath = fileName
        ? (segments.length > 0 ? segments.join('/') + '/' + fileName : fileName)
        : null

      const pathChanged = JSON.stringify(segments) !== JSON.stringify(currentPath.value)
        || filePath !== activeFile.value

      suppressHashSync = true
      currentPath.value = segments
      activeFile.value = filePath
      if (view === 'editor') {
        const ui = useUiStore()
        viewMode.value = (fileName && !ui.readonly && !isFilenameBlocked(fileName))
          ? 'editor' : 'preview'
      } else {
        viewMode.value = view
      }
      if (pathChanged) {
        editDirty.value = false
        selected.value = new Set()
        focusIndex.value = -1
        lastClickedPath.value = null
        searchQuery.value = ''
        searchRecursive.value = false
      }
      suppressHashSync = false
    } else {
      suppressHashSync = true
      try {
        await navigateTo(segments)
      } finally {
        suppressHashSync = false
      }
    }
  }

  const debouncedSyncToHash = useDebounceFn(syncToHash, 50)

  watch(
    [currentPath, viewMode, activeFile],
    () => debouncedSyncToHash(),
    { deep: true },
  )

  function init() {
    syncFromHash()
    window.addEventListener('hashchange', syncFromHash)
  }

  return {
    // Path & view
    currentPath,
    viewMode,
    activeFile,
    editDirty,
    currentPathStr,
    activeFileName,
    activeFilePath,

    // Selection
    selected,
    focusIndex,
    lastClickedPath,
    selectedCount,
    hasSelection,
    toggleSelect,
    clearSelection,
    setSelected,
    pruneSelection,
    moveFocus,
    setFocusIndex,

    // Search
    searchQuery,
    searchRecursive,

    // Navigation
    navigateTo,
    openFile,
    openEditor,
    backToList,
    checkDirty,
    init,
  }
})
