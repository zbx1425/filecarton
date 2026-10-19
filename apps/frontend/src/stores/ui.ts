import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useMediaQuery } from '@vueuse/core'
import type { FileCartonFeatures } from '@/api/types'

export const useUiStore = defineStore('ui', () => {
  const config = window.__FILECARTON__

  const readonly = ref(config.readonly)
  const repoName = ref(config.repoName ?? '')
  const branding = ref(config.branding ?? '')
  const features = ref<FileCartonFeatures>({
    archive: config.features?.archive ?? false,
    unixPerm: config.features?.unixPerm ?? false,
    unixPermModify: config.features?.unixPermModify ?? false,
  })
  const unixPermInfo = ref(config.unixPerm ?? { user: 'unknown', isRoot: false })

  const searchQuery = ref('')
  const searchRecursive = ref(false)
  const settingsOpen = ref(false)
  const dragOverlayVisible = ref(false)

  const isWide = useMediaQuery('(min-width: 900px)')
  const isMedium = useMediaQuery('(min-width: 700px)')

  const treePanelVisible = ref(true)
  const showTreeToggle = computed(() => isMedium.value)

  function initResponsive() {
    treePanelVisible.value = isWide.value
  }

  function toggleTreePanel() {
    treePanelVisible.value = !treePanelVisible.value
  }

  const shouldShowTree = computed(() => {
    if (isMedium.value) return treePanelVisible.value
    return false
  })

  return {
    readonly,
    repoName,
    branding,
    features,
    unixPermInfo,
    searchQuery,
    searchRecursive,
    settingsOpen,
    dragOverlayVisible,
    treePanelVisible,
    showTreeToggle,
    shouldShowTree,
    isWide,
    isMedium,
    initResponsive,
    toggleTreePanel,
  }
})
