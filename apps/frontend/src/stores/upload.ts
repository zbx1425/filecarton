import { translate } from '@/i18n'
import { defineStore } from 'pinia'
import { ref, computed, markRaw } from 'vue'
import { toast } from 'vue-sonner'
import { useThrottleFn } from '@vueuse/core'
import UploadToast from '@/components/upload/UploadToast.vue'
import type { Upload as TusUpload } from 'tus-js-client'
import { apiPost, toErrorInfo, ApiError, maybeUnauthorized } from '@/api/client'
import type { ErrorInfo } from '@/api/client'
import type { CheckUploadConflictsResponse } from '@/api/types'
import { showPasteConflict, confirm } from '@/services/dialogs'
import { errorMessage } from '@/utils/errorMessage'
import { useFileTreeCacheStore } from '@/stores/fileTreeCache'
import { useNavigationStore } from '@/stores/navigation'
import { joinPath, isAppleJunkName, isAppleJunkPath } from '@/utils/path'
import { sanitizeFileName, isDotFileBlocked, isFilenameBlocked } from '@/utils/pathValidation'
import { uploadViaTus } from '@/services/tusUploader'
import { useSpeedSampling } from '@/composables/useSpeedSampling'
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

  // ── Speed / ETA (delegated to composable) ──
  const { speed, eta, start: startSpeedSampling } =
    useSpeedSampling(bytesSent, totalBytes, hasActive)

  function show() { visible.value = true }
  function hide() { visible.value = false }

  // ── Upload progress toast ──
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

  // ── Path helpers ──

  function computeTargetPath(targetDir: string, relativePath: string, fileName: string): string {
    const safeName = sanitizeFileName(fileName) || fileName
    if (relativePath) {
      const dir = relativePath.split('/').slice(0, -1).join('/')
      const base = dir ? joinPath(targetDir, dir) : targetDir
      return joinPath(base, safeName)
    }
    return joinPath(targetDir, safeName)
  }

  // ── Validation ──

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
    } catch (e) {
      toast.error(errorMessage(e))
      return false
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
    const extra = oversized.length - 10
    const suffix = extra > 0 ? `\n${translate('common.list.and_more', { count: extra })}` : ''
    await confirm(
      translate('upload.too_large.title'),
      translate('upload.too_large.message', { count: oversized.length, limit, listing: listing + suffix }, oversized.length),
      { actionLabel: translate('common.action.ok'), hideCancel: true },
    )
    return false
  }

  function hasBlockedPathComponent(relativePath: string): boolean {
    if (!relativePath) return false
    const segments = relativePath.split('/').slice(0, -1)
    return segments.some(seg => isDotFileBlocked(seg))
  }

  /**
   * Filter out restricted entries and show a warning toast if any were blocked.
   */
  function filterEntries(
    entries: { file: File; relativePath: string }[],
  ): { file: File; relativePath: string }[] {
    const blocked: string[] = []
    const passed: typeof entries = []
    for (const entry of entries) {
      if (
        isDotFileBlocked(entry.file.name) ||
        isFilenameBlocked(entry.file.name) ||
        hasBlockedPathComponent(entry.relativePath)
      ) {
        blocked.push(entry.relativePath || entry.file.name)
      } else {
        passed.push(entry)
      }
    }
    if (blocked.length > 0) {
      const listing = blocked.slice(0, 5).join(', ')
      const extra = blocked.length - 5
      toast.warning(extra > 0
        ? translate('upload.blocked_more', { count: blocked.length, names: listing, extra })
        : translate('upload.blocked', { count: blocked.length, names: listing }, blocked.length))
    }
    return passed
  }

  // ── Queue management ──

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

  /**
   * Common pipeline: deduplicate → check oversize → check conflicts → enqueue.
   */
  async function prepareAndEnqueue(
    entries: { file: File; relativePath: string }[],
    targetDir: string,
  ): Promise<void> {
    if (entries.length === 0) return

    // Deduplicate by sanitized target path (last wins)
    const byTarget = new Map<string, (typeof entries)[0]>()
    let dupes = 0
    for (const entry of entries) {
      const key = computeTargetPath(targetDir, entry.relativePath, entry.file.name)
      if (byTarget.has(key)) dupes++
      byTarget.set(key, entry)
    }
    const deduped = [...byTarget.values()]
    if (dupes > 0) {
      toast.warning(translate('upload.duplicate', { count: dupes }, dupes))
    }

    if (!await checkOversized(deduped.map(e => e.file))) return

    const conflictEntries = deduped.map(e => ({
      fileName: e.file.name,
      relativePath: e.relativePath,
      targetDir,
    }))
    if (!await checkConflicts(conflictEntries)) return

    const batchId = crypto.randomUUID()
    enqueueTasks(batchId, deduped.map(({ file, relativePath }) => ({
      id: crypto.randomUUID(),
      batchId,
      file,
      relativePath,
      targetDir,
      progress: 0,
      status: 'pending' as const,
    })))
  }

  // ── Public entry points ──

  async function addFiles(fileList: FileList | File[], targetDir: string) {
    const rawFiles = Array.from(fileList).filter(f => !isAppleJunkName(f.name))
    const filtered = filterEntries(rawFiles.map(f => ({ file: f, relativePath: '' })))
    await prepareAndEnqueue(filtered, targetDir)
  }

  async function addFolderFiles(fileList: FileList, targetDir: string) {
    const rawFiles = Array.from(fileList).filter(f => {
      const rel = (f as any).webkitRelativePath || ''
      return !isAppleJunkName(f.name) && (!rel || !isAppleJunkPath(rel))
    })
    const entries = rawFiles.map(f => ({
      file: f,
      relativePath: (f as any).webkitRelativePath || '',
    }))
    const filtered = filterEntries(entries)
    await prepareAndEnqueue(filtered, targetDir)
  }

  async function addFilesWithPaths(
    files: { file: File; relativePath: string }[],
    targetDir: string,
  ) {
    if (files.length === 0) return
    const nonJunk = files.filter(e =>
      !isAppleJunkName(e.file.name) && (!e.relativePath || !isAppleJunkPath(e.relativePath)),
    )
    const filtered = filterEntries(nonJunk)
    await prepareAndEnqueue(filtered, targetDir)
  }

  // ── Upload execution ──

  async function processQueue() {
    while (activeTasks.value < MAX_CONCURRENT_UPLOADS) {
      const next = tasks.value.find(t => t.status === 'pending')
      if (!next) break
      next.status = 'uploading'
      runUploadTask(next)
    }
  }

  async function runUploadTask(task: UploadTask) {
    try {
      const { chunkSize } = getUploadConfig()
      await uploadViaTus(task, { chunkSize })
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
    if (task.status === 'success') {
      refreshAfterUpload(task)
    }

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

  // ── Retry / Remove / Cancel ──

  function retry(taskId: string) {
    const task = tasks.value.find(t => t.id === taskId)
    if (task && task.status === 'error') {
      tasks.value = tasks.value.filter(t => t.status !== 'success')
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
      startSpeedSampling()
      processQueue()
    }
  }

  function retryAllErrors() {
    const errs = tasks.value.filter(t => t.status === 'error')
    if (errs.length === 0) return
    tasks.value = tasks.value.filter(t => t.status !== 'success')
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
    startSpeedSampling()
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
  window.addEventListener('beforeunload', (e: BeforeUnloadEvent) => {
    if (hasActive.value) {
      e.preventDefault()
    }
  })

  const uploadDialogOpen = ref(false)
  function openUploadDialog() { uploadDialogOpen.value = true }
  function closeUploadDialog() { uploadDialogOpen.value = false }
  function openErrorDialog() { errorDialogOpen.value = true }
  function closeErrorDialog() { errorDialogOpen.value = false }

  // ── Post-upload directory refresh ──

  const pendingRelistDirs = new Set<string>()

  function doRefresh() {
    const dirs = new Set(pendingRelistDirs)
    pendingRelistDirs.clear()
    if (dirs.size === 0) return

    const cache = useFileTreeCacheStore()
    const navigation = useNavigationStore()
    const currentPath = navigation.currentPathStr

    if (dirs.has(currentPath)) {
      cache.fetchDirectory(currentPath, { background: true })
    }
  }

  const throttledRefresh = useThrottleFn(doRefresh, 1000, true, true)

  function refreshAfterUpload(task: UploadTask) {
    const cache = useFileTreeCacheStore()
    const safeName = sanitizeFileName(task.file.name) || task.file.name
    const needsRelist = cache.patchTreeForUpload(task.targetDir, task.relativePath, safeName)
    for (const dir of needsRelist) {
      pendingRelistDirs.add(dir)
    }
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
