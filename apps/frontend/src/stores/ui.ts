import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useMediaQuery } from '@vueuse/core'

export const useUiStore = defineStore('ui', () => {
  const config = window.__FILECARTON__

  const readonly = ref(config.readonly)
  const repoName = ref(config.repoName)

  const searchQuery = ref('')
  const searchRecursive = ref(false)

  const isWide = useMediaQuery('(min-width: 900px)')
  const isMedium = useMediaQuery('(min-width: 700px)')

  const treePanelVisible = ref(true)
  const showTreeToggle = computed(() => isMedium.value && !isWide.value)

  function initResponsive() {
    treePanelVisible.value = isWide.value
  }

  function toggleTreePanel() {
    treePanelVisible.value = !treePanelVisible.value
  }

  const shouldShowTree = computed(() => {
    if (isWide.value) return true
    if (isMedium.value) return treePanelVisible.value
    return false
  })

  return {
    readonly,
    repoName,
    searchQuery,
    searchRecursive,
    treePanelVisible,
    showTreeToggle,
    shouldShowTree,
    isWide,
    isMedium,
    initResponsive,
    toggleTreePanel,
  }
})
