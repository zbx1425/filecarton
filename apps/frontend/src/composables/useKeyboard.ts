import { onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useNavigationStore } from '@/stores/navigation'
import { useFileTreeCacheStore } from '@/stores/fileTreeCache'
import { useUiStore } from '@/stores/ui'
import { usePreferencesStore } from '@/stores/preferences'
import { isAnyDialogOpen } from '@/services/dialogs'
import { lightboxState, closeLightbox } from '@/composables/useLightbox'
import { handleArchiveBackKey } from '@/composables/useArchiveExplorer'
import {
  copySelected,
  cutSelected,
  deleteSelected,
  renameSelected,
  pasteItems,
  downloadSelected,
  refreshCurrent,
} from '@/services/fileActions'
import { useUploadStore } from '@/stores/upload'
import { errorMessage } from '@/utils/errorMessage'
import { toast } from 'vue-sonner'
import { useClipboardStore } from '@/stores/clipboard'
import { joinPath } from '@/utils/path'
import { sortItems, filterItems } from '@/utils/sorting'

function scrollFocusedRowIntoView(name: string) {
  nextTick(() => {
    const row = document.querySelector(`[data-file-row][data-name="${CSS.escape(name)}"]`)
    row?.scrollIntoView({ block: 'nearest' })
  })
}

/** Get the filtered+sorted entries for the current directory, using shared utils */
function getFilteredEntries(): Array<{ name: string; isDir: boolean }> {
  const cache = useFileTreeCacheStore()
  const navigation = useNavigationStore()
  const preferences = usePreferencesStore()

  const dirData = cache.getDirectory(navigation.currentPathStr)
  if (!dirData) return []

  const { sortBy, sortAsc, showDotFiles } = preferences
  const { searchQuery, searchRecursive } = navigation

  const sortedDirs = sortItems(dirData.dirs, sortBy, sortAsc, (d, col) => col === 'mtime' ? d.mtime : 0)
  const sortedFiles = sortItems(dirData.files, sortBy, sortAsc, (f, col) => col === 'size' ? f.size : f.mtime)

  const filteredDirs = filterItems(sortedDirs, showDotFiles, searchQuery, searchRecursive)
  const filteredFiles = filterItems(sortedFiles, showDotFiles, searchQuery, searchRecursive)

  const result: Array<{ name: string; isDir: boolean }> = []
  for (const d of filteredDirs) result.push({ name: d.name, isDir: true })
  for (const f of filteredFiles) result.push({ name: f.name, isDir: false })
  return result
}

export function useKeyboard() {
  const navigation = useNavigationStore()
  const ui = useUiStore()
  const clipboard = useClipboardStore()
  const upload = useUploadStore()

  function isAnyOverlayOpen(): boolean {
    return (
      isAnyDialogOpen.value
      || ui.settingsOpen
      || upload.uploadDialogOpen
      || upload.errorDialogOpen
    )
  }

  function isInputFocused(): boolean {
    const el = document.activeElement
    if (!el) return false
    const tag = el.tagName
    return tag === 'INPUT' || tag === 'TEXTAREA' || (el as HTMLElement).isContentEditable
  }

  function handleKeyDown(e: KeyboardEvent) {
    if (isAnyOverlayOpen()) return

    if (lightboxState.open) {
      if (e.key === 'Escape') {
        closeLightbox()
        e.preventDefault()
      }
      return
    }

    if (e.key === 'Escape' && ui.dragOverlayVisible) return

    if (navigation.viewMode === 'editor') {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault()
      }
      return
    }

    if (isInputFocused()) {
      if (e.key === 'Escape') {
        (document.activeElement as HTMLElement).blur()
        e.preventDefault()
      }
      return
    }

    const ctrl = e.ctrlKey || e.metaKey

    if (navigation.viewMode !== 'list') {
      if (ctrl && e.key.toLowerCase() === 'f') {
        const archiveSearch = document.querySelector<HTMLInputElement>('[data-archive-search]')
        if (archiveSearch) {
          e.preventDefault()
          archiveSearch.focus()
        }
        return
      }
      if (e.key === 'Escape' || e.key === 'Backspace') {
        if (!handleArchiveBackKey(e.key)) {
          navigation.backToList()
        }
        e.preventDefault()
      }
      return
    }

    if (ctrl && e.key === 'c' && !ui.readonly) {
      e.preventDefault()
      copySelected()
      return
    }
    if (ctrl && e.key === 'x' && !ui.readonly) {
      e.preventDefault()
      cutSelected()
      return
    }
    if (ctrl && e.key === 'v' && !ui.readonly && clipboard.hasContent) {
      e.preventDefault()
      pasteItems(navigation.currentPathStr)
      return
    }
    if (ctrl && e.key === 'a') {
      e.preventDefault()
      const entries = getFilteredEntries()
      const curPath = navigation.currentPathStr
      const allPaths = entries.map(en => joinPath(curPath, en.name))
      const allSelected = allPaths.length > 0 && allPaths.every(p => navigation.selected.has(p))
      if (allSelected) {
        navigation.clearSelection()
      } else {
        navigation.setSelected(new Set(allPaths))
      }
      return
    }
    if (ctrl && e.key === 'f') {
      e.preventDefault()
      const searchInput = document.querySelector<HTMLInputElement>('[data-search]')
      searchInput?.focus()
      return
    }

    if (e.key === 'F5') {
      e.preventDefault()
      refreshCurrent()
      return
    }
    if (e.key === 'F6') {
      e.preventDefault()
      if (navigation.hasSelection) {
        downloadSelected().catch((err) => {
          toast.error(errorMessage(err))
        })
      }
      return
    }

    if (e.key === 'Delete' && !ui.readonly && navigation.hasSelection) {
      e.preventDefault()
      deleteSelected()
      return
    }
    if (e.key === 'F2' && !ui.readonly && navigation.selectedCount === 1) {
      e.preventDefault()
      renameSelected()
      return
    }

    if (e.key === 'Escape') {
      if (navigation.hasSelection) {
        navigation.clearSelection()
      }
      return
    }

    if (e.key === 'Enter' && navigation.selectedCount === 1) {
      const absPath = Array.from(navigation.selected)[0]
      const entries = getFilteredEntries()
      const curPath = navigation.currentPathStr
      const prefix = curPath ? curPath + '/' : ''
      const name = prefix ? absPath.slice(prefix.length) : absPath
      const entry = entries.find(en => en.name === name)
      if (entry) {
        if (entry.isDir) {
          navigation.navigateTo([...navigation.currentPath, entry.name])
        } else {
          navigation.openFile(joinPath(curPath, entry.name))
        }
      }
      return
    }

    if (e.key === 'ArrowUp') {
      e.preventDefault()
      const entries = getFilteredEntries()
      navigation.moveFocus(-1, entries.length)
      const curPath = navigation.currentPathStr
      if (entries[navigation.focusIndex]) {
        const name = entries[navigation.focusIndex].name
        navigation.clearSelection()
        navigation.toggleSelect(joinPath(curPath, name))
        scrollFocusedRowIntoView(name)
      }
      return
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      const entries = getFilteredEntries()
      navigation.moveFocus(1, entries.length)
      const curPath = navigation.currentPathStr
      if (entries[navigation.focusIndex]) {
        const name = entries[navigation.focusIndex].name
        navigation.clearSelection()
        navigation.toggleSelect(joinPath(curPath, name))
        scrollFocusedRowIntoView(name)
      }
      return
    }

    if (e.key === 'Backspace' && navigation.currentPath.length > 0) {
      navigation.navigateTo(navigation.currentPath.slice(0, -1))
      return
    }
  }

  onMounted(() => {
    window.addEventListener('keydown', handleKeyDown)
  })

  onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeyDown)
  })
}
