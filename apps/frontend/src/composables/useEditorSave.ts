import { ref, watch, onBeforeUnmount } from 'vue'
import type * as Monaco from 'monaco-editor'
import { toast } from 'vue-sonner'
import { translate } from '@/i18n'
import { apiGet, apiWrite, ApiError } from '@/api/client'
import type { ReadResponse } from '@/api/types'
import { useNavigationStore } from '@/stores/navigation'
import { useFileTreeCacheStore } from '@/stores/fileTreeCache'
import { showEditorConflict } from '@/services/dialogs'
import { errorMessage } from '@/utils/errorMessage'
import { parentPath, pathName } from '@/utils/path'

function updateFileEntry(filePath: string, result: { size: number; mtime: number }) {
  const cache = useFileTreeCacheStore()
  const dirPath = parentPath(filePath)
  const fileName = pathName(filePath)
  cache.updateFileEntry(dirPath, fileName, result)
}

export function useEditorSave() {
  const navigation = useNavigationStore()
  const saving = ref(false)
  let currentMtime = 0
  let beforeUnloadHandler: ((e: BeforeUnloadEvent) => void) | null = null

  function setMtime(mtime: number) {
    currentMtime = mtime
  }

  watch(() => navigation.editDirty, (dirty) => {
    if (dirty && !beforeUnloadHandler) {
      beforeUnloadHandler = (e: BeforeUnloadEvent) => { e.preventDefault() }
      window.addEventListener('beforeunload', beforeUnloadHandler)
    } else if (!dirty && beforeUnloadHandler) {
      window.removeEventListener('beforeunload', beforeUnloadHandler)
      beforeUnloadHandler = null
    }
  })

  onBeforeUnmount(() => {
    if (beforeUnloadHandler) {
      window.removeEventListener('beforeunload', beforeUnloadHandler)
      beforeUnloadHandler = null
    }
  })

  async function save(editor: Monaco.editor.IStandaloneCodeEditor, filePath: string) {
    if (saving.value) return

    saving.value = true
    const savedPath = filePath
    const savedVersion = editor.getModel()?.getAlternativeVersionId()
    try {
      const result = await apiWrite(savedPath, editor.getValue(), currentMtime)
      if (navigation.activeFilePath === savedPath) {
        currentMtime = result.mtime
        if (editor.getModel()?.getAlternativeVersionId() === savedVersion) {
          navigation.editDirty = false
        }
      }
      updateFileEntry(savedPath, result)
      toast.success(translate('toast.file_saved'))
    } catch (e: unknown) {
      if (e instanceof ApiError && e.authError) {
        /* handled by the global onUnauthorized handler */
      } else if (e instanceof ApiError && e.status === 409) {
        const choice = await showEditorConflict()
        if (choice === 'overwrite') {
          try {
            const owVersion = editor.getModel()?.getAlternativeVersionId()
            const result = await apiWrite(savedPath, editor.getValue())
            if (navigation.activeFilePath === savedPath) {
              currentMtime = result.mtime
              if (editor.getModel()?.getAlternativeVersionId() === owVersion) {
                navigation.editDirty = false
              }
            }
            updateFileEntry(savedPath, result)
            toast.success(translate('toast.file_saved_overwritten'))
          } catch (e2: unknown) {
            if (e2 instanceof ApiError && e2.authError) {
              /* handled by the global onUnauthorized handler */
            } else {
              toast.error(errorMessage(e2))
            }
          }
        } else if (choice === 'reload') {
          await reload(editor, savedPath)
        }
      } else {
        toast.error(errorMessage(e))
      }
    } finally {
      saving.value = false
    }
  }

  async function reload(editor: Monaco.editor.IStandaloneCodeEditor, filePath: string) {
    try {
      const data = await apiGet<ReadResponse>('read', { path: filePath })
      editor.setValue(data.content)
      currentMtime = data.mtime
      navigation.editDirty = false
      toast.info(translate('toast.file_reloaded'))
    } catch (e: unknown) {
      if (e instanceof ApiError && e.authError) {
        /* handled by maybeUnauthorized */
      } else {
        toast.error(errorMessage(e))
      }
    }
  }

  return { saving, currentMtime, setMtime, save, reload }
}
