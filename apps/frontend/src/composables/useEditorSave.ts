import { ref, watch, onBeforeUnmount } from 'vue'
import type * as Monaco from 'monaco-editor'
import { toast } from 'vue-sonner'
import { useI18n } from 'vue-i18n'
import { apiGet, apiWrite, ApiError, toErrorInfo } from '@/api/client'
import type { ReadResponse } from '@/api/types'
import { useNavigationStore } from '@/stores/navigation'
import { useFileListStore } from '@/stores/fileList'
import { showEditorConflict } from '@/composables/useDialogs'

function updateFileEntry(filePath: string, result: { size: number; mtime: number }) {
  const navigation = useNavigationStore()
  const parentPath = filePath.includes('/') ? filePath.slice(0, filePath.lastIndexOf('/')) : ''
  if (navigation.currentPathStr !== parentPath) return
  const fileList = useFileListStore()
  const fileName = filePath.includes('/') ? filePath.slice(filePath.lastIndexOf('/') + 1) : filePath
  const entry = fileList.files.find(f => f.name === fileName)
  if (entry) {
    entry.size = result.size
    entry.mtime = result.mtime
  }
}

export function useEditorSave() {
  const navigation = useNavigationStore()
  const { t } = useI18n()

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
      toast.success('File saved')
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
            toast.success('File saved (overwritten)')
          } catch (e2: unknown) {
            if (e2 instanceof ApiError && e2.authError) {
              /* handled by the global onUnauthorized handler */
            } else {
              const info2 = toErrorInfo(e2)
              toast.error(t(`err.${info2.code}`, (info2.params as Record<string, unknown>) ?? {}))
            }
          }
        } else if (choice === 'reload') {
          await reload(editor, savedPath)
        }
      } else {
        const info = toErrorInfo(e)
        toast.error(t(`err.${info.code}`, (info.params as Record<string, unknown>) ?? {}))
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
      toast.info('File reloaded')
    } catch (e: unknown) {
      if (e instanceof ApiError && e.authError) {
        /* handled by maybeUnauthorized */
      } else {
        toast.error(t(`err.${toErrorInfo(e).code}`))
      }
    }
  }

  return { saving, currentMtime, setMtime, save, reload }
}
