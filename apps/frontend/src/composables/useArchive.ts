import { toast } from 'vue-sonner'
import { translate } from '@/i18n'
import { apiPost } from '@/api/client'
import type { ArchiveCreateResponse, ArchiveExtractResponse, ArchiveExtractDryRunResponse } from '@/api/types'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import { confirm, showPasteConflict, showOperationReport } from '@/services/dialogs'
import { handleMutation } from '@/services/treeInvalidation'
import { errorMessage } from '@/utils/errorMessage'

function selectedNamesInCurrentDir(): string[] {
  const navigation = useNavigationStore()
  const curPath = navigation.currentPathStr
  const prefix = curPath ? curPath + '/' : ''
  const names: string[] = []
  for (const p of navigation.selected) {
    if (prefix ? p.startsWith(prefix) && !p.slice(prefix.length).includes('/') : !p.includes('/')) {
      names.push(prefix ? p.slice(prefix.length) : p)
    }
  }
  return names
}

export async function createArchive(
  format: 'zip' | 'tar',
  dirPath?: string,
  items?: string[],
) {
  const ui = useUiStore()
  if (ui.readonly || !ui.features.archive) return

  const navigation = useNavigationStore()

  const effectiveDirPath = dirPath ?? navigation.currentPathStr
  const effectiveItems = items ?? selectedNamesInCurrentDir()
  if (effectiveItems.length === 0) return

  const toastId = toast.loading(translate('toast.archive_creating', { format: `.${format}` }))
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
        title: translate('archive.report.created_title'),
        description: translate('archive.report.created_skipped', { name: archiveName, count: result.skipped.length }, result.skipped.length),
        items: result.skipped.map(s => ({ name: s, reason: 'blocked.ignored' })),
      })
    } else {
      toast.success(translate('toast.archive_created', { name: archiveName }), { id: toastId })
    }
    handleMutation({ dirs: [effectiveDirPath] })
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

  let activeToastId: string | number | undefined = toast.loading(translate('toast.archive_inspecting'))
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
        title: translate('archive.report.extract_failed_title'),
        description: translate('archive.report.extract_failed'),
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
        title: translate('archive.report.restricted_title'),
        description: translate('archive.report.restricted', { count: dryRun.failed.length }, dryRun.failed.length),
        items: dryRun.failed.map(f => ({ name: f.path, reason: f.reason })),
        continueLabel: translate('archive.report.continue', { count: dryRun.wouldExtract }, dryRun.wouldExtract),
      })
      if (!proceed) return
    } else if (!hasConflicts) {
      const confirmed = await confirm(
        translate('archive.confirm.title'),
        displayTarget
          ? translate('archive.confirm.message', { count: dryRun.wouldExtract, path: displayTarget }, dryRun.wouldExtract)
          : translate('archive.confirm.message_root', { count: dryRun.wouldExtract }, dryRun.wouldExtract),
        { actionLabel: translate('common.action.extract') },
      )
      if (!confirmed) return
    }

    activeToastId = toast.loading(translate('toast.archive_extracting'))
    const result = await apiPost<ArchiveExtractResponse>('archive_extract', {
      path: archivePath,
      targetPath: targetDir,
      createSubdir,
    })
    if (result.failed && result.failed.length > 0) {
      toast.dismiss(activeToastId)
      activeToastId = undefined
      await showOperationReport({
        title: translate('archive.report.partial_title'),
        description: translate('archive.report.partial', { extracted: result.extracted, skipped: result.failed.length }),
        items: result.failed.map(f => ({ name: f.path, reason: f.reason })),
      })
    } else {
      toast.success(translate('toast.archive_extracted', { count: result.extracted }, result.extracted), { id: activeToastId })
    }
    handleMutation({ subtrees: [targetDir] })
  } catch (e: unknown) {
    toast.error(errorMessage(e), activeToastId !== undefined ? { id: activeToastId } : undefined)
  }
}
