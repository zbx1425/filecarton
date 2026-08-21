import { toast } from 'vue-sonner'
import { apiPost } from '@/api/client'
import type { ArchiveCreateResponse, ArchiveExtractResponse } from '@/api/types'
import { useFileListStore } from '@/stores/fileList'
import { useNavigationStore } from '@/stores/navigation'
import { useTreeStore } from '@/stores/tree'
import { confirm } from '@/composables/useDialogs'

export async function createArchive(format: 'zip' | 'tar') {
  const fileList = useFileListStore()
  const navigation = useNavigationStore()
  const tree = useTreeStore()

  const items = Array.from(fileList.selected)
  if (items.length === 0) return

  try {
    const result = await apiPost<ArchiveCreateResponse>('archive', {
      operation: 'create',
      format,
      path: navigation.currentPathStr,
      items,
    })
    toast.success(`Archive created: ${result.archivePath.split('/').pop()}`)
    fileList.fetchDir(navigation.currentPathStr)
    tree.invalidate(navigation.currentPathStr)
    tree.loadChildren(navigation.currentPathStr)
  } catch (e: unknown) {
    toast.error(e instanceof Error ? e.message : 'Failed to create archive')
  }
}

export async function extractArchive(archivePath: string, targetDir: string) {
  const navigation = useNavigationStore()
  const fileList = useFileListStore()
  const tree = useTreeStore()

  const confirmed = await confirm(
    'Extract Archive',
    `Extract to /${targetDir || '(root)'}?`,
    { actionLabel: 'Extract' },
  )
  if (!confirmed) return

  try {
    const result = await apiPost<ArchiveExtractResponse>('archive', {
      operation: 'extract',
      path: archivePath,
      targetPath: targetDir,
      createSubdir: false,
    })
    toast.success(`Extracted ${result.extracted} files`)
    fileList.fetchDir(navigation.currentPathStr)
    tree.invalidate(targetDir)
    tree.loadChildren(targetDir)
  } catch (e: unknown) {
    toast.error(e instanceof Error ? e.message : 'Failed to extract')
  }
}
