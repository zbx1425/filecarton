import { onMounted, onBeforeUnmount } from 'vue'
import { useNavigationStore } from '@/stores/navigation'
import { useFileListStore } from '@/stores/fileList'
import { useUiStore } from '@/stores/ui'
import { dialogState } from '@/composables/useDialogs'
import { lightboxState, closeLightbox } from '@/composables/useLightbox'
import {
  copySelected,
  cutSelected,
  deleteSelected,
  renameSelected,
  pasteItems,
} from '@/composables/useFileActions'
import { useClipboardStore } from '@/stores/clipboard'

export function useKeyboard() {
  const navigation = useNavigationStore()
  const fileList = useFileListStore()
  const ui = useUiStore()
  const clipboard = useClipboardStore()

  function isAnyDialogOpen(): boolean {
    return (
      dialogState.confirm.open
      || dialogState.prompt.open
      || dialogState.pasteConflict.open
      || dialogState.editorConflict.open
    )
  }

  function isInputFocused(): boolean {
    const el = document.activeElement
    if (!el) return false
    const tag = el.tagName
    return tag === 'INPUT' || tag === 'TEXTAREA' || (el as HTMLElement).isContentEditable
  }

  function handleKeyDown(e: KeyboardEvent) {
    if (isAnyDialogOpen()) return

    if (lightboxState.open) {
      if (e.key === 'Escape') {
        closeLightbox()
        e.preventDefault()
      }
      return
    }

    if (e.key === 'Escape' && ui.dragOverlayVisible) return

    if (navigation.viewMode === 'editor') return

    if (navigation.viewMode !== 'list') {
      if (e.key === 'Escape' || e.key === 'Backspace') {
        navigation.backToList()
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
      fileList.toggleSelectAll()
      return
    }
    if (ctrl && e.key === 'f') {
      e.preventDefault()
      const searchInput = document.querySelector<HTMLInputElement>('[placeholder="Search..."]')
      searchInput?.focus()
      return
    }

    if (e.key === 'Delete' && !ui.readonly && fileList.hasSelection) {
      e.preventDefault()
      deleteSelected()
      return
    }
    if (e.key === 'F2' && !ui.readonly && fileList.selectedCount === 1) {
      e.preventDefault()
      renameSelected()
      return
    }

    if (e.key === 'Escape') {
      if (fileList.hasSelection) {
        fileList.clearSelection()
      }
      return
    }

    if (e.key === 'Enter' && fileList.selectedCount === 1) {
      const name = Array.from(fileList.selected)[0]
      const entry = fileList.filteredEntries.find(en => en.name === name)
      if (entry) {
        if (entry.isDir) {
          navigation.navigateTo([...navigation.currentPath, entry.name])
        } else {
          navigation.openFile(entry.name)
        }
      }
      return
    }

    if (e.key === 'ArrowUp') {
      e.preventDefault()
      fileList.moveFocus(-1)
      const entries = fileList.filteredEntries
      if (entries[fileList.focusIndex]) {
        fileList.clearSelection()
        fileList.toggleSelect(entries[fileList.focusIndex].name)
      }
      return
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      fileList.moveFocus(1)
      const entries = fileList.filteredEntries
      if (entries[fileList.focusIndex]) {
        fileList.clearSelection()
        fileList.toggleSelect(entries[fileList.focusIndex].name)
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
