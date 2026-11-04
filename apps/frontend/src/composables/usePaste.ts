import { toast } from 'vue-sonner'
import { apiPost } from '@/api/client'
import type { PasteResponse } from '@/api/types'
import { useNavigationStore } from '@/stores/navigation'
import { showPasteConflict, showOperationReport } from '@/composables/useDialogs'
import { invalidateFileTree, type MutationRefreshOptions } from '@/composables/useFileTree'
import { errorMessage } from '@/utils/errorMessage'

export interface PasteParams {
  mode: 'copy' | 'cut'
  sourcePath: string
  items: string[]
  targetPath: string
}

function verb(mode: 'copy' | 'cut', past: boolean) {
  return past
    ? (mode === 'copy' ? 'Copied' : 'Moved')
    : (mode === 'copy' ? 'Copy' : 'Move')
}

function reportResult(
  result: PasteResponse,
  mode: 'copy' | 'cut',
  toastId: string | number | undefined,
) {
  const v = verb(mode, true)
  const vNoun = verb(mode, false)
  if (result.failed.length > 0 && result.completed === 0) {
    if (toastId !== undefined) toast.dismiss(toastId)
    return showOperationReport({
      title: `${vNoun} Failed`,
      description: 'All items failed.',
      items: result.failed.map(f => ({ name: f.name, reason: f.error })),
    })
  }
  if (result.failed.length > 0) {
    if (toastId !== undefined) toast.dismiss(toastId)
    return showOperationReport({
      title: `Partial ${vNoun}`,
      description: `${v} ${result.completed} item(s), ${result.failed.length} failed.`,
      items: result.failed.map(f => ({ name: f.name, reason: f.error })),
    })
  }
  toast.success(`${v} ${result.completed} item(s)`, toastId !== undefined ? { id: toastId } : undefined)
}

/**
 * Execute a paste operation with conflict resolution and result reporting.
 * Returns the total number of completed items.
 */
export async function runPaste(params: PasteParams): Promise<number> {
  const { mode, sourcePath, items, targetPath } = params
  const loadingText = `${verb(mode, false) === 'Copy' ? 'Copying' : 'Moving'} ${items.length} item(s)...`
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
    invalidateFileTree(refreshOpts)

    return totalCompleted
  } catch (e: unknown) {
    toast.error(errorMessage(e), activeToastId !== undefined ? { id: activeToastId } : undefined)
    return 0
  }
}
