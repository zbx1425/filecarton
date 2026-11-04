import { computed, toValue, type MaybeRefOrGetter } from 'vue'
import { useFileTreeCacheStore } from '@/stores/fileTreeCache'
import { useTreeUiStore } from '@/stores/treeUi'
import { useNavigationStore } from '@/stores/navigation'
import { useClipboardStore } from '@/stores/clipboard'
import { parentPath } from '@/utils/path'

export interface TreeNodeProps {
  path: MaybeRefOrGetter<string>
  name: MaybeRefOrGetter<string>
  type: MaybeRefOrGetter<'dir' | 'file' | string>
}

export function useTreeNavigation(props: TreeNodeProps) {
  const cache = useFileTreeCacheStore()
  const treePanel = useTreeUiStore()
  const navigation = useNavigationStore()
  const clipboard = useClipboardStore()

  const isDir = computed(() => toValue(props.type) === 'dir')
  const pathVal = computed(() => toValue(props.path))
  const nameVal = computed(() => toValue(props.name))

  const isExpanded = computed(() => isDir.value && treePanel.isExpanded(pathVal.value))
  const isLoading = computed(() => cache.isTreeLoading(pathVal.value))
  const children = computed(() => cache.getTreeChildren(pathVal.value) ?? [])

  const dirPath = computed(() => {
    if (isDir.value) return parentPath(pathVal.value)
    const p = pathVal.value
    return p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : ''
  })

  const isActive = computed(() => {
    if (isDir.value) {
      return navigation.currentPathStr === pathVal.value
    }
    return navigation.activeFile === pathVal.value
  })

  const isCut = computed(() => clipboard.isCutItem(dirPath.value, nameVal.value))

  const editDirty = computed(() => navigation.editDirty)

  const canPaste = computed(() => clipboard.hasContent && isDir.value)

  // ── Actions ──

  async function handleClick() {
    if (isDir.value) {
      const segments = pathVal.value ? pathVal.value.split('/') : []
      await navigation.navigateTo(segments)
    } else {
      const parent = pathVal.value.includes('/')
        ? pathVal.value.slice(0, pathVal.value.lastIndexOf('/'))
        : ''
      const segments = parent ? parent.split('/') : []
      if (navigation.currentPathStr !== parent) {
        const ok = await navigation.navigateTo(segments)
        if (!ok) return
      }
      await navigation.openFile(pathVal.value)
    }
  }

  function handleToggle() {
    treePanel.toggleExpand(pathVal.value)
  }

  function handleCopy() {
    clipboard.copy([{ name: nameVal.value, isDir: isDir.value }], dirPath.value)
  }

  function handleCut() {
    clipboard.cut([{ name: nameVal.value, isDir: isDir.value }], dirPath.value)
  }

  return {
    // Node state
    isDir,
    isExpanded,
    isLoading,
    children,
    isActive,
    isCut,
    dirPath,
    editDirty,
    canPaste,

    // Actions
    handleClick,
    handleToggle,
    handleCopy,
    handleCut,
  }
}
