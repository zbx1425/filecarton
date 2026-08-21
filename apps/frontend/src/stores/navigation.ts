import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import { EDITABLE_EXTENSIONS } from '@/utils/constants'
import { pathExtension, joinPath } from '@/utils/path'
import { confirm } from '@/composables/useDialogs'
import { useUiStore } from '@/stores/ui'

export const useNavigationStore = defineStore('navigation', () => {
  const currentPath = ref<string[]>([])
  const viewMode = ref<'list' | 'preview' | 'editor'>('list')
  const activeFile = ref<string | null>(null)
  const editDirty = ref(false)

  const currentPathStr = computed(() => currentPath.value.join('/'))

  const activeFilePath = computed(() => {
    if (!activeFile.value) return null
    return joinPath(currentPathStr.value, activeFile.value)
  })

  let suppressHashSync = false

  async function checkDirty(): Promise<boolean> {
    if (!editDirty.value) return true
    const leave = await confirm('Unsaved Changes', 'You have unsaved changes. Leave without saving?', {
      actionLabel: 'Leave',
      danger: true,
    })
    return leave
  }

  async function navigateTo(path: string[]) {
    if (viewMode.value === 'editor' && editDirty.value) {
      const canLeave = await checkDirty()
      if (!canLeave) return
    }
    viewMode.value = 'list'
    activeFile.value = null
    editDirty.value = false
    currentPath.value = path
  }

  async function openFile(fileName: string) {
    if (viewMode.value === 'editor' && editDirty.value) {
      const canLeave = await checkDirty()
      if (!canLeave) return
    }
    activeFile.value = fileName
    const ext = pathExtension(fileName)
    const editable = EDITABLE_EXTENSIONS.has(ext)
      || fileName.toLowerCase() === 'makefile' || fileName.toLowerCase() === 'dockerfile'
    const ui = useUiStore()
    viewMode.value = (editable && !ui.readonly) ? 'editor' : 'preview'
    editDirty.value = false
  }

  function openEditor() {
    viewMode.value = 'editor'
  }

  async function backToList() {
    if (viewMode.value === 'editor' && editDirty.value) {
      const canLeave = await checkDirty()
      if (!canLeave) return
    }
    viewMode.value = 'list'
    activeFile.value = null
    editDirty.value = false
  }

  function syncToHash() {
    if (suppressHashSync) return
    const encodedSegments = currentPath.value.map(s => encodeURIComponent(s))
    let hash = '#/' + encodedSegments.join('/')
    if (activeFile.value) {
      hash += (currentPath.value.length > 0 ? '/' : '') + encodeURIComponent(activeFile.value)
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

    const segments = pathPart.split('/').filter(Boolean).map(s => decodeURIComponent(s))

    const params = new URLSearchParams(queryPart)
    const view = params.get('view')

    if (view === 'preview' || view === 'editor') {
      const fileName = segments.pop()
      suppressHashSync = true
      currentPath.value = segments
      activeFile.value = fileName ?? null
      viewMode.value = view
      editDirty.value = false
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
    currentPath,
    viewMode,
    activeFile,
    editDirty,
    currentPathStr,
    activeFilePath,
    navigateTo,
    openFile,
    openEditor,
    backToList,
    init,
  }
})
