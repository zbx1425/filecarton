import { defineStore } from 'pinia'
import { ref, computed, markRaw } from 'vue'
import { toast } from 'vue-sonner'
import { useThrottleFn } from '@vueuse/core'
import UploadToast from '@/components/upload/UploadToast.vue'
import { Upload as TusUpload, DetailedError } from 'tus-js-client'
import { apiPost, toErrorInfo, ApiError, maybeUnauthorized, buildTusEndpointUrl, getCsrfToken } from '@/api/client'
import type { ErrorInfo } from '@/api/client'
import type { CheckUploadConflictsResponse } from '@/api/types'
import { createPostOnlyHttpStack } from '@/utils/tusHttpStack'
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
  status: 'pending' | 'uploading' | 'success' | 'error' | 'cancelled'
  error?: ErrorInfo
  tusUpload?: TusUpload
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

  const totalBytes = computed(() =>
    tasks.value
      .filter(t => t.status !== 'error' && t.status !== 'cancelled')
      .reduce((s, t) => s + t.file.size, 0),
  )

  const bytesSent = computed(() =>
    tasks.value
      .filter(t => t.status !== 'error' && t.status !== 'cancelled')
      .reduce((s, t) => s + t.file.size * t.progress / 100, 0),
  )

  const completedCount = computed(() =>
    tasks.value.filter(t => t.status === 'success').length,
  )

  const speed = ref(0)
  const eta = ref(-1)
  let speedSamples: { time: number; bytes: number }[] = []
  let speedInterval: ReturnType<typeof setInterval> | null = null

  function startSpeedSampling() {
    if (speedInterval) return
    speedSamples = [{ time: Date.now(), bytes: bytesSent.value }]
    speed.value = 0
    eta.value = -1
    speedInterval = setInterval(() => {
      const now = Date.now()
      const sent = bytesSent.value
      speedSamples.push({ time: now, bytes: sent })
      const cutoff = now - 5000
      while (speedSamples.length > 1 && speedSamples[0].time < cutoff) {
        speedSamples.shift()
      }
      if (speedSamples.length >= 2) {
        const first = speedSamples[0]
        const last = speedSamples[speedSamples.length - 1]
        const dt = (last.time - first.time) / 1000
        if (dt > 0) {
          speed.value = (last.bytes - first.bytes) / dt
          const remaining = totalBytes.value - sent
          eta.value = speed.value > 0 ? remaining / speed.value : -1
        }
      }
      if (!hasActive.value) stopSpeedSampling()
    }, 500)
  }

  function stopSpeedSampling() {
    if (speedInterval) {
      clearInterval(speedInterval)
      speedInterval = null
    }
    speedSamples = []
    speed.value = 0
    eta.value = -1
  }

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
    const wasFresh = !hasActive.value
    if (wasFresh) {
      activeTotal.value = newTasks.length
    } else {
      activeTotal.value += newTasks.length
    }
    const newPaths = new Set(
      newTasks.map(t => computeTargetPath(t.targetDir, t.relativePath, t.file.name)),
    )
    tasks.value = [
      ...tasks.value.filter(t => {
        if (wasFresh && t.status === 'success') return false
        if (t.status === 'error' && newPaths.has(computeTargetPath(t.targetDir, t.relativePath, t.file.name))) return false
        return true
      }),
      ...newTasks,
    ]
    visible.value = true
    showUploadToast()
    startSpeedSampling()
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
        prefs.isFilenameBlocked(file.name) ||
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
        prefs.isFilenameBlocked(entry.file.name) ||
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
    try {
      await uploadViaTus(task)
      if (task.status !== 'cancelled') {
        task.status = 'success'
        task.progress = 100
      }
    } catch (e: unknown) {
      if (task.status !== 'cancelled') {
        task.status = 'error'
        task.error = toErrorInfo(e)
        if (e instanceof ApiError) maybeUnauthorized('tus', e)
      }
    }
    processQueue()
    refreshAfterUpload(task.targetDir)

    if (!hasActive.value) {
      doRefresh()
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

  function uploadViaTus(task: UploadTask): Promise<void> {
    const { chunkSize } = getUploadConfig()
    let targetDir = task.targetDir
    if (task.relativePath) {
      const dir = task.relativePath.split('/').slice(0, -1).join('/')
      if (dir) targetDir = joinPath(task.targetDir, dir)
    }

    return new Promise<void>((resolve, reject) => {
      const upload = new TusUpload(task.file, {
        endpoint: buildTusEndpointUrl(),
        chunkSize,
        retryDelays: [0, 1000, 3000, 5000],
        uploadDataDuringCreation: true,
        overridePatchMethod: true,
        httpStack: createPostOnlyHttpStack(),
        storeFingerprintForResuming: false,
        removeFingerprintOnSuccess: true,
        headers: {
          'X-CSRF-Token': getCsrfToken(),
        },
        metadata: {
          filename: task.file.name,
          targetPath: targetDir,
        },
        onProgress(bytesSent, bytesTotal) {
          task.progress = bytesTotal === 0 ? 100 : Math.round((bytesSent / bytesTotal) * 100)
        },
        onSuccess() {
          task.tusUpload = undefined
          task.progress = 100
          resolve()
        },
        onError(error) {
          task.tusUpload = undefined
          if (task.status === 'cancelled') {
            resolve()
            return
          }
          if (error instanceof DetailedError) {
            const resp = error.originalResponse
            if (resp) {
              const status = resp.getStatus()
              let body: any = null
              try { body = JSON.parse(resp.getBody()) } catch { /* non-JSON */ }
              const code = body?.error ?? `http_${status}`
              reject(new ApiError(code, status, body?.params, body?.authError))
              return
            }
          }
          reject(new ApiError('network_error', 0))
        },
        onShouldRetry(error) {
          if (task.status === 'cancelled') return false
          const resp = error.originalResponse
          if (!resp) return true
          const status = resp.getStatus()
          if (status === 409) {
            try {
              const body = JSON.parse(resp.getBody())
              if (body?.error) return false
            } catch { /* non-JSON body = offset mismatch, retryable */ }
            return true
          }
          return status === 423 || status === 429 || status >= 500
        },
      })
      task.tusUpload = markRaw(upload)
      upload.start()
    })
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

  function cancelAll() {
    for (const task of tasks.value) {
      if (task.status === 'pending') {
        task.status = 'cancelled'
      } else if (task.status === 'uploading') {
        task.status = 'cancelled'
        if (task.tusUpload) {
          task.tusUpload.abort(true).catch(() => {})
          task.tusUpload = undefined
        }
      }
    }
    tasks.value = tasks.value.filter(t => t.status !== 'cancelled')
    if (!hasActive.value) {
      snapshotLatestBatch()
      remeasureToast()
      dismissUploadToast()
    }
  }

  // Warn before closing the page while uploads are in progress
  function onBeforeUnload(e: BeforeUnloadEvent) {
    if (hasActive.value) {
      e.preventDefault()
    }
  }
  window.addEventListener('beforeunload', onBeforeUnload)

  const uploadDialogOpen = ref(false)
  function openUploadDialog() { uploadDialogOpen.value = true }
  function closeUploadDialog() { uploadDialogOpen.value = false }
  function openErrorDialog() { errorDialogOpen.value = true }
  function closeErrorDialog() { errorDialogOpen.value = false }

  const pendingRefreshDirs = new Set<string>()

  function doRefresh() {
    const dirs = new Set(pendingRefreshDirs)
    pendingRefreshDirs.clear()
    if (dirs.size === 0) return

    const navigation = useNavigationStore()
    const fileList = useFileListStore()
    const tree = useTreeStore()

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
  }

  const throttledRefresh = useThrottleFn(doRefresh, 1000, true, true)

  function refreshAfterUpload(targetDir: string) {
    pendingRefreshDirs.add(targetDir)
    throttledRefresh()
  }

  return {
    visible,
    tasks,
    activeTasks,
    activeTotal,
    hasActive,
    errorTasks,
    oldErrorCount,
    totalBytes,
    bytesSent,
    completedCount,
    speed,
    eta,
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
    cancelAll,
    openUploadDialog,
    closeUploadDialog,
    openErrorDialog,
    closeErrorDialog,
  }
})
