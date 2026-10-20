import { toast } from 'vue-sonner'
import { apiPost } from '@/api/client'
import type { ArchiveCreateResponse, ArchiveExtractResponse, ArchiveExtractDryRunResponse } from '@/api/types'
import { useFileListStore } from '@/stores/fileList'
import { useNavigationStore } from '@/stores/navigation'
import { useTreeStore } from '@/stores/tree'
import { useUiStore } from '@/stores/ui'
import { confirm, showPasteConflict, showOperationReport } from '@/composables/useDialogs'
import { errorMessage } from '@/utils/errorMessage'

export async function createArchive(
  format: 'zip' | 'tar',
  dirPath?: string,
  items?: string[],
) {
  const ui = useUiStore()
  if (ui.readonly || !ui.features.archive) return

  const fileList = useFileListStore()
  const navigation = useNavigationStore()
  const tree = useTreeStore()

  const effectiveDirPath = dirPath ?? navigation.currentPathStr
  const effectiveItems = items ?? Array.from(fileList.selected)
  if (effectiveItems.length === 0) return

  const toastId = toast.loading(`Creating .${format} archive...`)
  try {
    const result = await apiPost<ArchiveCreateResponse>('archive_create', {
      format,
      path: effectiveDirPath,
      items: effectiveItems,
    })
    const archiveName = result.archivePath.split('/').pop()
    if (result.skipped && result.skipped.length > 0) {
      toast.dismiss(toastId)
      await showOperationReport({
        title: 'Archive Created',
        description: `${archiveName} created. ${result.skipped.length} item(s) were skipped due to restrictions.`,
        items: result.skipped.map(s => ({ name: s, reason: 'blocked.ignored' })),
      })
    } else {
      toast.success(`Archive created: ${archiveName}`, { id: toastId })
    }
    fileList.fetchDir(navigation.currentPathStr)
    tree.invalidate(effectiveDirPath)
    tree.loadChildren(effectiveDirPath)
  } catch (e: unknown) {
    toast.error(errorMessage(e), { id: toastId })
  }
}

export async function extractArchive(
  archivePath: string,
  targetDir: string,
  createSubdir = false,
) {
  const ui = useUiStore()
  if (ui.readonly || !ui.features.archive) return

  const navigation = useNavigationStore()
  const fileList = useFileListStore()
  const tree = useTreeStore()

  let activeToastId: string | number | undefined = toast.loading('Inspecting archive...')
  try {
    const dryRun = await apiPost<ArchiveExtractDryRunResponse>('archive_extract', {
      path: archivePath,
      targetPath: targetDir,
      createSubdir,
      dryRun: true,
    })
    toast.dismiss(activeToastId)
    activeToastId = undefined

    const hasFailed = dryRun.failed && dryRun.failed.length > 0
    const hasConflicts = dryRun.conflicts.length > 0

    if (hasFailed && !hasConflicts && dryRun.wouldExtract === 0) {
      await showOperationReport({
        title: 'Extraction Failed',
        description: 'All entries were rejected due to restrictions.',
        items: dryRun.failed.map(f => ({ name: f.path, reason: f.reason })),
      })
      return
    }

    if (hasConflicts) {
      const overwrite = await showPasteConflict(dryRun.conflicts)
      if (!overwrite) return
    }

    const displayTarget = dryRun.targetPath || targetDir
    if (hasFailed) {
      const proceed = await showOperationReport({
        title: 'Entries Restricted',
        description: `${dryRun.failed.length} entry(ies) will be skipped during extraction.`,
        items: dryRun.failed.map(f => ({ name: f.path, reason: f.reason })),
        continueLabel: `Extract ${dryRun.wouldExtract} file(s)`,
      })
      if (!proceed) return
    } else if (!hasConflicts) {
      const confirmed = await confirm(
        'Extract Archive',
        `Extract ${dryRun.wouldExtract} file(s) to /${displayTarget || '(root)'}?`,
        { actionLabel: 'Extract' },
      )
      if (!confirmed) return
    }

    activeToastId = toast.loading('Extracting archive...')
    const result = await apiPost<ArchiveExtractResponse>('archive_extract', {
      path: archivePath,
      targetPath: targetDir,
      createSubdir,
    })
    if (result.failed && result.failed.length > 0) {
      toast.dismiss(activeToastId)
      activeToastId = undefined
      await showOperationReport({
        title: 'Partial Extraction',
        description: `Extracted ${result.extracted} file(s), ${result.failed.length} skipped.`,
        items: result.failed.map(f => ({ name: f.path, reason: f.reason })),
      })
    } else {
      toast.success(`Extracted ${result.extracted} files`, { id: activeToastId })
    }
    fileList.fetchDir(navigation.currentPathStr)
    tree.invalidateSubtree(targetDir)
    tree.loadChildren(targetDir)
  } catch (e: unknown) {
    toast.error(errorMessage(e), activeToastId !== undefined ? { id: activeToastId } : undefined)
  }
}
