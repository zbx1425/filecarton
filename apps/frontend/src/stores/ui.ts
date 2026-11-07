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
    remoteDownload: config.features?.remoteDownload ?? false,
    checksum: config.features?.checksum ?? true,
    copyLink: config.features?.copyLink ?? '',
  })
  const unixPermInfo = ref(config.unixPerm ?? { user: 'unknown', isRoot: false })

  const settingsOpen = ref(false)
  const dragOverlayVisible = ref(false)
  const mobileDrawerOpen = ref(false)

  const isMobile = useMediaQuery('(width < 40rem)')
  const isLarge = useMediaQuery('(width >= 64rem)')

  const treePanelVisible = ref(true)
  const showTreeToggle = computed(() => !isMobile.value)

  function initResponsive() {
    treePanelVisible.value = isLarge.value
  }

  function toggleTreePanel() {
    treePanelVisible.value = !treePanelVisible.value
  }

  const shouldShowTree = computed(() => {
    if (isMobile.value) return false
    return treePanelVisible.value
  })

  return {
    readonly,
    repoName,
    branding,
    features,
    unixPermInfo,
    settingsOpen,
    dragOverlayVisible,
    mobileDrawerOpen,
    treePanelVisible,
    showTreeToggle,
    shouldShowTree,
    isMobile,
    isLarge,
    initResponsive,
    toggleTreePanel,
  }
})
