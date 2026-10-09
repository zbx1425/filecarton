import { defineStore } from 'pinia'
import { ref, computed, markRaw } from 'vue'
import { toast } from 'vue-sonner'
import { useDebounceFn } from '@vueuse/core'
import UploadToast from '@/components/upload/UploadToast.vue'
import { apiUpload, apiPost, toErrorInfo, ApiError } from '@/api/client'
import type { ErrorInfo } from '@/api/client'
import type { UploadResponse, UploadChunkResponse, UploadCompleteResponse, CheckUploadConflictsResponse } from '@/api/types'
import { showPasteConflict, confirm } from '@/composables/useDialogs'
import { useFileListStore } from '@/stores/fileList'
import { useNavigationStore } from '@/stores/navigation'
import { useTreeStore } from '@/stores/tree'
import { usePreferencesStore } from '@/stores/preferences'
import { joinPath, parentPath, isAppleJunkName, isAppleJunkPath } from '@/utils/path'
import { CHUNK_SIZE as DEFAULT_CHUNK_SIZE, MAX_CONCURRENT_UPLOADS } from '@/utils/constants'
import { formatSize } from '@/utils/format'

export interface UploadTask {
  id: string
  batchId: string
  file: File
  relativePath: string
  targetDir: string
  progress: number
  status: 'pending' | 'uploading' | 'success' | 'error'
  error?: ErrorInfo
}

export interface BatchResult {
  total: number
  success: number
  failed: number
}

function getUploadConfig() {
  const cfg = window.__FILECARTON__?.upload
  return {
    chunkSize: cfg?.chunkSize ?? DEFAULT_CHUNK_SIZE,
    maxFileSize: cfg?.maxFileSize ?? Infinity,
  }
}

export const useUploadStore = defineStore('upload', () => {
  const visible = ref(false)
  const tasks = ref<UploadTask[]>([])
  const latestBatchId = ref<string | null>(null)
  const latestBatchResult = ref<BatchResult | null>(null)
  const errorDialogOpen = ref(false)
  const activeTotal = ref(0)
  let toastId: string | number | null = null

  const activeTasks = computed(() => tasks.value.filter(t => t.status === 'uploading').length)
  const hasActive = computed(() => tasks.value.some(t => t.status === 'uploading' || t.status === 'pending'))
  const errorTasks = computed(() => tasks.value.filter(t => t.status === 'error'))
  const oldErrorCount = computed(() =>
    errorTasks.value.filter(t => t.batchId !== latestBatchId.value).length,
  )

  function show() { visible.value = true }
  function hide() { visible.value = false }

  let dismissTimer: ReturnType<typeof setTimeout> | null = null

  function showUploadToast() {
    if (dismissTimer) {
      clearTimeout(dismissTimer)
      dismissTimer = null
    }
    if (toastId != null) return
    toastId = toast(markRaw(UploadToast), {
      duration: Infinity,
      dismissible: false,
      id: 'upload-progress',
    })
  }

  function dismissUploadToast() {
    if (toastId == null) return
    if (errorTasks.value.length > 0) return
    dismissTimer = setTimeout(() => {
      dismissTimer = null
      toast.dismiss(toastId!)
      toastId = null
    }, 3000)
  }

  function forceCloseToast() {
    if (dismissTimer) {
      clearTimeout(dismissTimer)
      dismissTimer = null
    }
    if (toastId != null) {
      toast.dismiss(toastId)
      toastId = null
    }
  }

  function remeasureToast() {
    if (toastId == null) return
    toast(markRaw(UploadToast), {
      id: 'upload-progress',
      duration: Infinity,
      dismissible: false,
    })
  }

  function computeTargetPath(targetDir: string, relativePath: string, fileName: string): string {
    if (relativePath) {
      const dir = relativePath.split('/').slice(0, -1).join('/')
      const base = dir ? joinPath(targetDir, dir) : targetDir
      return joinPath(base, fileName)
    }
    return joinPath(targetDir, fileName)
  }

  async function checkConflicts(
    entries: Array<{ fileName: string; relativePath: string; targetDir: string }>,
  ): Promise<boolean> {
    const paths = entries.map(e => computeTargetPath(e.targetDir, e.relativePath, e.fileName))
    try {
      const result = await apiPost<CheckUploadConflictsResponse>('check_upload_conflicts', { paths })
      if (result.existing.length > 0) {
        return await showPasteConflict(result.existing)
      }
      return true
    } catch {
      return true
    }
  }

  async function checkOversized(files: File[]): Promise<boolean> {
    const { maxFileSize } = getUploadConfig()
    if (maxFileSize === Infinity) return true
    const oversized = files.filter(f => f.size > maxFileSize)
    if (oversized.length === 0) return true
    const limit = formatSize(maxFileSize)
    const listing = oversized
      .slice(0, 10)
      .map(f => `${f.name} (${formatSize(f.size)})`)
      .join('\n')
    const suffix = oversized.length > 10 ? `\n...and ${oversized.length - 10} more` : ''
    await confirm(
      'Files Too Large',
      `${oversized.length} file(s) exceed the ${limit} upload limit:\n\n${listing}${suffix}\n\nPlease remove or compress these files before uploading.`,
      { actionLabel: 'OK', hideCancel: true },
    )
    return false
  }

  function enqueueTasks(batchId: string, newTasks: UploadTask[]) {
    latestBatchId.value = batchId
    latestBatchResult.value = null
    if (!hasActive.value) {
      activeTotal.value = newTasks.length
    } else {
      activeTotal.value += newTasks.length
    }
    const newPaths = new Set(
      newTasks.map(t => computeTargetPath(t.targetDir, t.relativePath, t.file.name)),
    )
    tasks.value = [
      ...tasks.value.filter(t =>
        t.status !== 'error' || !newPaths.has(computeTargetPath(t.targetDir, t.relativePath, t.file.name)),
      ),
      ...newTasks,
    ]
    visible.value = true
    showUploadToast()
    processQueue()
  }

  function hasBlockedPathComponent(relativePath: string): boolean {
    if (!relativePath) return false
    const prefs = usePreferencesStore()
    const segments = relativePath.split('/').slice(0, -1)
    return segments.some(seg => prefs.isDotFileBlocked(seg))
  }

  function filterRestricted(files: File[]): File[] {
    const prefs = usePreferencesStore()
    const blocked: string[] = []
    const passed: File[] = []
    for (const file of files) {
      const relPath = (file as any).webkitRelativePath || ''
      if (
        prefs.isDotFileBlocked(file.name) ||
        prefs.isExtensionBlocked(file.name) ||
        hasBlockedPathComponent(relPath)
      ) {
        blocked.push(relPath || file.name)
      } else {
        passed.push(file)
      }
    }
    if (blocked.length > 0) {
      const listing = blocked.slice(0, 5).join(', ')
      const suffix = blocked.length > 5 ? ` and ${blocked.length - 5} more` : ''
      toast.warning(`${blocked.length} file(s) not uploaded due to restrictions: ${listing}${suffix}`)
    }
    return passed
  }

  async function addFiles(fileList: FileList | File[], targetDir: string) {
    let allFiles = filterRestricted(Array.from(fileList).filter(f => !isAppleJunkName(f.name)))
    if (allFiles.length === 0) return
    if (!await checkOversized(allFiles)) return

    const entries = allFiles.map(f => ({ fileName: f.name, relativePath: '', targetDir }))
    if (!await checkConflicts(entries)) return

    const batchId = crypto.randomUUID()
    enqueueTasks(batchId, allFiles.map(file => ({
      id: crypto.randomUUID(),
      batchId,
      file,
      relativePath: '',
      targetDir,
      progress: 0,
      status: 'pending' as const,
    })))
  }

  async function addFolderFiles(fileList: FileList, targetDir: string) {
    const rawFiles = Array.from(fileList).filter(f => {
      const rel = (f as any).webkitRelativePath || ''
      return !isAppleJunkName(f.name) && (!rel || !isAppleJunkPath(rel))
    })
    const allowedSet = new Set(filterRestricted(rawFiles))
    const allFiles = rawFiles.filter(f => allowedSet.has(f))
    if (allFiles.length === 0) return
    if (!await checkOversized(allFiles)) return

    const entries = allFiles.map(file => ({
      fileName: file.name,
      relativePath: (file as any).webkitRelativePath || '',
      targetDir,
    }))
    if (!await checkConflicts(entries)) return

    const batchId = crypto.randomUUID()
    enqueueTasks(batchId, allFiles.map(file => ({
      id: crypto.randomUUID(),
      batchId,
      file,
      relativePath: (file as any).webkitRelativePath || '',
      targetDir,
      progress: 0,
      status: 'pending' as const,
    })))
  }

  async function addFilesWithPaths(
    files: { file: File; relativePath: string }[],
    targetDir: string,
  ) {
    if (files.length === 0) return
    const nonJunk = files.filter(e =>
      !isAppleJunkName(e.file.name) && (!e.relativePath || !isAppleJunkPath(e.relativePath)),
    )
    if (nonJunk.length === 0) return
    const prefs = usePreferencesStore()
    const blocked: string[] = []
    const passed: typeof files = []
    for (const entry of nonJunk) {
      if (
        prefs.isDotFileBlocked(entry.file.name) ||
        prefs.isExtensionBlocked(entry.file.name) ||
        hasBlockedPathComponent(entry.relativePath)
      ) {
        blocked.push(entry.relativePath || entry.file.name)
      } else {
        passed.push(entry)
      }
    }
    if (blocked.length > 0) {
      const listing = blocked.slice(0, 5).join(', ')
      const suffix = blocked.length > 5 ? ` and ${blocked.length - 5} more` : ''
      toast.warning(`${blocked.length} file(s) not uploaded due to restrictions: ${listing}${suffix}`)
    }
    if (passed.length === 0) return
    if (!await checkOversized(passed.map(f => f.file))) return

    const entries = passed.map(({ file, relativePath }) => ({
      fileName: file.name,
      relativePath,
      targetDir,
    }))
    if (!await checkConflicts(entries)) return

    const batchId = crypto.randomUUID()
    enqueueTasks(batchId, passed.map(({ file, relativePath }) => ({
      id: crypto.randomUUID(),
      batchId,
      file,
      relativePath,
      targetDir,
      progress: 0,
      status: 'pending' as const,
    })))
  }

  async function processQueue() {
    while (activeTasks.value < MAX_CONCURRENT_UPLOADS) {
      const next = tasks.value.find(t => t.status === 'pending')
      if (!next) break
      next.status = 'uploading'
      uploadTask(next)
    }
  }

  async function uploadTask(task: UploadTask) {
    const { chunkSize } = getUploadConfig()
    try {
      if (task.file.size > chunkSize) {
        await uploadChunked(task)
      } else {
        await uploadSingle(task)
      }
      task.status = 'success'
      task.progress = 100
    } catch (e: unknown) {
      task.status = 'error'
      task.error = toErrorInfo(e)
    }
    processQueue()
    refreshAfterUpload(task.targetDir)

    if (!hasActive.value) {
      snapshotLatestBatch()
      remeasureToast()
      dismissUploadToast()
      setTimeout(() => {
        if (!hasActive.value) {
          tasks.value = tasks.value.filter(t => t.status !== 'success')
          if (tasks.value.length === 0) {
            visible.value = false
          }
        }
      }, 4000)
    }
  }

  function snapshotLatestBatch() {
    if (!latestBatchId.value) return
    const batch = tasks.value.filter(t => t.batchId === latestBatchId.value)
    if (batch.length === 0) return
    latestBatchResult.value = {
      total: batch.length,
      success: batch.filter(t => t.status === 'success').length,
      failed: batch.filter(t => t.status === 'error').length,
    }
  }

  async function uploadSingle(task: UploadTask) {
    const formData = new FormData()

    let targetPath = task.targetDir
    if (task.relativePath) {
      const dir = task.relativePath.split('/').slice(0, -1).join('/')
      if (dir) targetPath = joinPath(task.targetDir, dir)
    }
    formData.append('path', targetPath)

    formData.append('files[]', task.file)
    const result = await apiUpload<UploadResponse>('upload', formData, (loaded, total) => {
      task.progress = Math.round((loaded / total) * 100)
    })
    if (result.failed && result.failed.length > 0) {
      const fail = result.failed[0]
      throw new ApiError(fail.error || 'server_error', 200, fail.params)
    }
    task.progress = 100
  }

  async function uploadChunked(task: UploadTask) {
    const { chunkSize } = getUploadConfig()
    const uploadId = crypto.randomUUID()
    const totalChunks = Math.ceil(task.file.size / chunkSize)

    for (let i = 0; i < totalChunks; i++) {
      const start = i * chunkSize
      const end = Math.min(start + chunkSize, task.file.size)
      const chunk = task.file.slice(start, end)

      const formData = new FormData()
      formData.append('uploadId', uploadId)
      formData.append('chunkIndex', String(i))
      formData.append('totalChunks', String(totalChunks))
      formData.append('chunk', chunk)

      let retries = 3
      while (retries > 0) {
        try {
          await apiUpload<UploadChunkResponse>('upload_chunk', formData)
          break
        } catch {
          retries--
          if (retries === 0) throw new Error(`Chunk ${i} failed after 3 retries`)
        }
      }

      task.progress = Math.round(((i + 1) / totalChunks) * 95)
    }

    const targetDir = task.relativePath
      ? joinPath(task.targetDir, task.relativePath.split('/').slice(0, -1).join('/'))
      : task.targetDir

    await apiPost<UploadCompleteResponse>('upload_complete', {
      uploadId,
      targetPath: targetDir,
      fileName: task.file.name,
      totalChunks,
    })
    task.progress = 100
  }

  function retry(taskId: string) {
    const task = tasks.value.find(t => t.id === taskId)
    if (task && task.status === 'error') {
      const retryBatchId = crypto.randomUUID()
      latestBatchId.value = retryBatchId
      latestBatchResult.value = null
      if (!hasActive.value) {
        activeTotal.value = 1
      } else {
        activeTotal.value++
      }
      task.batchId = retryBatchId
      task.status = 'pending'
      task.progress = 0
      task.error = undefined
      showUploadToast()
      processQueue()
    }
  }

  function retryAllErrors() {
    const errs = tasks.value.filter(t => t.status === 'error')
    if (errs.length === 0) return
    const retryBatchId = crypto.randomUUID()
    latestBatchId.value = retryBatchId
    latestBatchResult.value = null
    if (!hasActive.value) {
      activeTotal.value = errs.length
    } else {
      activeTotal.value += errs.length
    }
    for (const t of errs) {
      t.batchId = retryBatchId
      t.status = 'pending'
      t.progress = 0
      t.error = undefined
    }
    errorDialogOpen.value = false
    showUploadToast()
    processQueue()
  }

  function remove(taskId: string) {
    tasks.value = tasks.value.filter(t => t.id !== taskId)
    if (errorTasks.value.length === 0 && !hasActive.value) {
      forceCloseToast()
      visible.value = false
    }
  }

  function clearErrors() {
    tasks.value = tasks.value.filter(t => t.status !== 'error')
    errorDialogOpen.value = false
    if (!hasActive.value) {
      forceCloseToast()
      visible.value = false
    }
  }

  const uploadDialogOpen = ref(false)
  function openUploadDialog() { uploadDialogOpen.value = true }
  function closeUploadDialog() { uploadDialogOpen.value = false }
  function openErrorDialog() { errorDialogOpen.value = true }
  function closeErrorDialog() { errorDialogOpen.value = false }

  const pendingRefreshDirs = new Set<string>()

  const flushRefresh = useDebounceFn(() => {
    const navigation = useNavigationStore()
    const fileList = useFileListStore()
    const tree = useTreeStore()

    const dirs = new Set(pendingRefreshDirs)
    pendingRefreshDirs.clear()

    for (const dir of dirs) {
      tree.invalidateSubtree(dir)
      tree.loadChildren(dir)
      let ancestor = parentPath(dir)
      while (ancestor) {
        tree.invalidate(ancestor)
        tree.loadChildren(ancestor)
        ancestor = parentPath(ancestor)
      }
      tree.invalidate('')
      tree.loadChildren('')
    }

    if (dirs.has(navigation.currentPathStr)) {
      fileList.fetchDir(navigation.currentPathStr)
    }
  }, 500)

  function refreshAfterUpload(targetDir: string) {
    pendingRefreshDirs.add(targetDir)
    flushRefresh()
  }

  return {
    visible,
    tasks,
    activeTasks,
    activeTotal,
    hasActive,
    errorTasks,
    oldErrorCount,
    latestBatchId,
    latestBatchResult,
    errorDialogOpen,
    uploadDialogOpen,
    show,
    hide,
    addFiles,
    addFolderFiles,
    addFilesWithPaths,
    retry,
    retryAllErrors,
    remove,
    clearErrors,
    openUploadDialog,
    closeUploadDialog,
    openErrorDialog,
    closeErrorDialog,
  }
})
