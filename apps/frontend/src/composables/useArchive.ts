import { toast } from 'vue-sonner'
import { apiPost } from '@/api/client'
import type { ArchiveCreateResponse, ArchiveExtractResponse, ArchiveExtractDryRunResponse } from '@/api/types'
import { useFileListStore } from '@/stores/fileList'
import { useNavigationStore } from '@/stores/navigation'
import { useTreeStore } from '@/stores/tree'
import { confirm, showPasteConflict, showOperationReport } from '@/composables/useDialogs'

export async function createArchive(
  format: 'zip' | 'tar',
  dirPath?: string,
  items?: string[],
) {
  const fileList = useFileListStore()
  const navigation = useNavigationStore()
  const tree = useTreeStore()

  const effectiveDirPath = dirPath ?? navigation.currentPathStr
  const effectiveItems = items ?? Array.from(fileList.selected)
  if (effectiveItems.length === 0) return

  try {
    const result = await apiPost<ArchiveCreateResponse>('archive', {
      operation: 'create',
      format,
      path: effectiveDirPath,
      items: effectiveItems,
    })
    toast.success(`Archive created: ${result.archivePath.split('/').pop()}`)
    fileList.fetchDir(navigation.currentPathStr)
    tree.invalidate(effectiveDirPath)
    tree.loadChildren(effectiveDirPath)
  } catch (e: unknown) {
    toast.error(e instanceof Error ? e.message : 'Failed to create archive')
  }
}

export async function extractArchive(archivePath: string, targetDir: string) {
  const navigation = useNavigationStore()
  const fileList = useFileListStore()
  const tree = useTreeStore()

  try {
    const dryRun = await apiPost<ArchiveExtractDryRunResponse>('archive', {
      operation: 'extract',
      path: archivePath,
      targetPath: targetDir,
      createSubdir: false,
      dryRun: true,
    })

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
        `Extract ${dryRun.wouldExtract} file(s) to /${targetDir || '(root)'}?`,
        { actionLabel: 'Extract' },
      )
      if (!confirmed) return
    }

    const result = await apiPost<ArchiveExtractResponse>('archive', {
      operation: 'extract',
      path: archivePath,
      targetPath: targetDir,
      createSubdir: false,
    })
    if (result.failed && result.failed.length > 0) {
      await showOperationReport({
        title: 'Extraction Incomplete',
        description: `Extracted ${result.extracted} file(s), ${result.failed.length} skipped.`,
        items: result.failed.map(f => ({ name: f.path, reason: f.reason })),
      })
    } else {
      toast.success(`Extracted ${result.extracted} files`)
    }
    fileList.fetchDir(navigation.currentPathStr)
    tree.invalidateSubtree(targetDir)
    tree.loadChildren(targetDir)
  } catch (e: unknown) {
    toast.error(e instanceof Error ? e.message : 'Failed to extract')
  }
}
