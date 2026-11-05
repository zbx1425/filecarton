import { toast } from 'vue-sonner'
import { translate } from '@/i18n'
import { apiPost } from '@/api/client'
import type { PasteResponse } from '@/api/types'
import { useNavigationStore } from '@/stores/navigation'
import { showPasteConflict, showOperationReport } from '@/services/dialogs'
import { handleMutation, type MutationRefreshOptions } from '@/services/treeInvalidation'
import { errorMessage } from '@/utils/errorMessage'

export interface PasteParams {
  mode: 'copy' | 'cut'
  sourcePath: string
  items: string[]
  targetPath: string
}

function reportResult(
  result: PasteResponse,
  mode: 'copy' | 'cut',
  toastId: string | number | undefined,
) {
  const failed = result.failed.length
  const completed = result.completed
  if (failed > 0 && completed === 0) {
    if (toastId !== undefined) toast.dismiss(toastId)
    return showOperationReport({
      title: translate(mode === 'copy' ? 'paste.failed_copy' : 'paste.failed_move'),
      description: translate('paste.all_failed'),
      items: result.failed.map(f => ({ name: f.name, reason: f.error })),
    })
  }
  if (failed > 0) {
    if (toastId !== undefined) toast.dismiss(toastId)
    return showOperationReport({
      title: translate(mode === 'copy' ? 'paste.partial_copy_title' : 'paste.partial_move_title'),
      description: translate(
        mode === 'copy' ? 'paste.partial_copy' : 'paste.partial_move',
        { completed, failed },
      ),
      items: result.failed.map(f => ({ name: f.name, reason: f.error })),
    })
  }
  const successKey = mode === 'copy' ? 'paste.success_copy' : 'paste.success_move'
  toast.success(
    translate(successKey, { count: completed }, completed),
    toastId !== undefined ? { id: toastId } : undefined,
  )
}

/**
 * Execute a paste operation with conflict resolution and result reporting.
 * Returns the total number of completed items.
 */
export async function runPaste(params: PasteParams): Promise<number> {
  const { mode, sourcePath, items, targetPath } = params
  const loadingText = translate(
    mode === 'copy' ? 'paste.copying' : 'paste.moving',
    { count: items.length },
    items.length,
  )
  let activeToastId: string | number | undefined = toast.loading(loadingText)

  try {
    const check = await apiPost<PasteResponse>('paste', {
      mode, sourcePath, items, targetPath, overwrite: false,
    })

    let totalCompleted = check.completed

    if (check.conflicts.length > 0 && check.completed === 0) {
      toast.dismiss(activeToastId)
      activeToastId = undefined

      const overwrite = await showPasteConflict(check.conflicts)
      if (!overwrite) return 0

      activeToastId = toast.loading(loadingText)
      const result = await apiPost<PasteResponse>('paste', {
        mode, sourcePath, items, targetPath, overwrite: true,
      })
      totalCompleted = result.completed
      await reportResult(result, mode, activeToastId)
    } else {
      await reportResult(check, mode, activeToastId)
    }

    const navigation = useNavigationStore()

    if (mode === 'cut') {
      const currentStr = navigation.currentPathStr
      for (const item of items) {
        const movedPath = sourcePath ? `${sourcePath}/${item}` : item
        if (currentStr === movedPath || currentStr.startsWith(movedPath + '/')) {
          const suffix = currentStr === movedPath ? '' : currentStr.slice(movedPath.length + 1)
          const newBase = targetPath ? `${targetPath}/${item}` : item
          const newPath = suffix ? `${newBase}/${suffix}` : newBase
          await navigation.navigateTo(newPath.split('/'))
          break
        }
      }
    }

    const refreshOpts: MutationRefreshOptions = { subtrees: [targetPath] }
    if (mode === 'cut') {
      refreshOpts.dirs = [sourcePath]
      refreshOpts.removed = items.map(n => sourcePath ? `${sourcePath}/${n}` : n)
    }
    handleMutation(refreshOpts)

    return totalCompleted
  } catch (e: unknown) {
    toast.error(errorMessage(e), activeToastId !== undefined ? { id: activeToastId } : undefined)
    return 0
  }
}
