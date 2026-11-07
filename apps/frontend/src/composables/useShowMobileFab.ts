import { computed, inject } from 'vue'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import { FILE_LIST_VIEW_KEY } from '@/components/files/fileListContext'

/** Same predicate the shell uses to mount the speed dial. */
export function useShowMobileFab() {
  const ui = useUiStore()
  const navigation = useNavigationStore()
  const view = inject(FILE_LIST_VIEW_KEY)
  return computed(() => {
    if (!view) return false
    const recursiveSearch = navigation.viewMode === 'list'
      && navigation.searchRecursive
      && navigation.searchQuery.trim().length > 0
    const fileTableMounted = navigation.viewMode === 'list' && !recursiveSearch
    return ui.isMobile
      && navigation.viewMode === 'list'
      && !ui.readonly
      && !(fileTableMounted && view.selectedCount > 0)
  })
}
