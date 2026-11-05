import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import { EDITABLE_EXTENSIONS } from '@/utils/constants'
import { pathExtension, pathName } from '@/utils/path'
import { translate } from '@/i18n'
import { confirm } from '@/services/dialogs'
import { useUiStore } from '@/stores/ui'
import { isFilenameBlocked } from '@/utils/pathValidation'
import { useBrowserState } from '@/composables/useBrowserState'

/**
 * Check whether the editor has unsaved changes and prompt the user.
 */
export async function checkDirty(isDirty: boolean): Promise<boolean> {
  if (!isDirty) return true
  return confirm(translate('common.dialog.unsaved_changes'), translate('files.leave.message'), {
    actionLabel: translate('common.action.leave'),
    danger: true,
  })
}

export const useNavigationStore = defineStore('navigation', () => {
  const currentPath = ref<string[]>([])
  const viewMode = ref<'list' | 'preview' | 'editor'>('list')
  const activeFile = ref<string | null>(null)
  const editDirty = ref(false)

  // Delegate selection + search to shared composable
  const browsing = useBrowserState()

  // ── Computed ──
  const currentPathStr = computed(() => currentPath.value.join('/'))

  const activeFileName = computed(() => {
    if (!activeFile.value) return null
    return pathName(activeFile.value)
  })

  const activeFilePath = computed(() => activeFile.value)

  // ── Hash sync ──

  let suppressHashSync = false

  // ── Navigation ──

  async function navigateTo(path: string[]): Promise<boolean> {
    if (viewMode.value === 'editor' && editDirty.value) {
      if (!await checkDirty(editDirty.value)) return false
    }
    viewMode.value = 'list'
    activeFile.value = null
    editDirty.value = false
    currentPath.value = path
    browsing.resetBrowsingState()
    return true
  }

  async function openFile(filePath: string): Promise<boolean> {
    if (viewMode.value === 'editor' && editDirty.value) {
      if (!await checkDirty(editDirty.value)) return false
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
      if (!await checkDirty(editDirty.value)) return false
    }
    viewMode.value = 'list'
    activeFile.value = null
    editDirty.value = false
    return true
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
        if (!await checkDirty(editDirty.value)) {
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
        browsing.resetBrowsingState()
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

    // Selection (from useBrowserState)
    selected: browsing.selected,
    focusIndex: browsing.focusIndex,
    lastClickedPath: browsing.lastClickedPath,
    selectedCount: browsing.selectedCount,
    hasSelection: browsing.hasSelection,
    toggleSelect: browsing.toggleSelect,
    clearSelection: browsing.clearSelection,
    setSelected: browsing.setSelected,
    pruneSelection: browsing.pruneSelection,
    moveFocus: browsing.moveFocus,
    setFocusIndex: browsing.setFocusIndex,

    // Search (from useBrowserState)
    searchQuery: browsing.searchQuery,
    searchRecursive: browsing.searchRecursive,

    // Navigation
    navigateTo,
    openFile,
    openEditor,
    backToList,
    init,
  }
})
