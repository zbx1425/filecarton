import { defineStore } from 'pinia'
import { ref, computed, markRaw } from 'vue'
import { toast } from 'vue-sonner'
import { useDebounceFn } from '@vueuse/core'
import UploadToast from '@/components/upload/UploadToast.vue'
import { apiUpload, apiPost } from '@/api/client'
import type { UploadResponse, UploadChunkResponse, UploadCompleteResponse } from '@/api/types'
import { useFileListStore } from '@/stores/fileList'
import { useNavigationStore } from '@/stores/navigation'
import { useTreeStore } from '@/stores/tree'
import { joinPath, parentPath } from '@/utils/path'
import { CHUNK_SIZE, MAX_CONCURRENT_UPLOADS } from '@/utils/constants'

export interface UploadTask {
  id: string
  file: File
  relativePath: string
  targetDir: string
  progress: number
  status: 'pending' | 'uploading' | 'success' | 'error'
  error?: string
}

export const useUploadStore = defineStore('upload', () => {
  const visible = ref(false)
  const tasks = ref<UploadTask[]>([])
  let toastId: string | number | null = null

  const activeTasks = computed(() => tasks.value.filter(t => t.status === 'uploading').length)
  const hasActive = computed(() => tasks.value.some(t => t.status === 'uploading' || t.status === 'pending'))

  function show() { visible.value = true }
  function hide() { visible.value = false }

  function showUploadToast() {
    if (toastId != null) return
    toastId = toast(markRaw(UploadToast), {
      duration: Infinity,
      dismissible: false,
      id: 'upload-progress',
    })
  }

  function dismissUploadToast() {
    if (toastId != null) {
      setTimeout(() => {
        toast.dismiss(toastId!)
        toastId = null
      }, 3000)
    }
  }

  function addFiles(fileList: FileList | File[], targetDir: string) {
    const newTasks: UploadTask[] = []
    for (const file of fileList) {
      newTasks.push({
        id: crypto.randomUUID(),
        file,
        relativePath: '',
        targetDir,
        progress: 0,
        status: 'pending',
      })
    }
    tasks.value = [...tasks.value, ...newTasks]
    visible.value = true
    showUploadToast()
    processQueue()
  }

  function addFolderFiles(fileList: FileList, targetDir: string) {
    const newTasks: UploadTask[] = []
    for (const file of fileList) {
      newTasks.push({
        id: crypto.randomUUID(),
        file,
        relativePath: (file as any).webkitRelativePath || '',
        targetDir,
        progress: 0,
        status: 'pending',
      })
    }
    tasks.value = [...tasks.value, ...newTasks]
    visible.value = true
    showUploadToast()
    processQueue()
  }

  function addFilesWithPaths(
    files: { file: File; relativePath: string }[],
    targetDir: string,
  ) {
    const newTasks: UploadTask[] = files.map(({ file, relativePath }) => ({
      id: crypto.randomUUID(),
      file,
      relativePath,
      targetDir,
      progress: 0,
      status: 'pending' as const,
    }))
    tasks.value = [...tasks.value, ...newTasks]
    visible.value = true
    showUploadToast()
    processQueue()
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
      if (task.file.size > CHUNK_SIZE) {
        await uploadChunked(task)
      } else {
        await uploadSingle(task)
      }
      task.status = 'success'
      task.progress = 100
    } catch (e: unknown) {
      task.status = 'error'
      task.error = e instanceof Error ? e.message : 'Upload failed'
    }
    processQueue()
    refreshAfterUpload(task.targetDir)

    if (!hasActive.value) {
      dismissUploadToast()
      setTimeout(() => {
        if (!hasActive.value) {
          tasks.value = tasks.value.filter(t => t.status !== 'success')
          if (tasks.value.length === 0) visible.value = false
        }
      }, 4000)
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
    await apiUpload<UploadResponse>('upload', formData)
    task.progress = 100
  }

  async function uploadChunked(task: UploadTask) {
    const uploadId = crypto.randomUUID()
    const totalChunks = Math.ceil(task.file.size / CHUNK_SIZE)

    for (let i = 0; i < totalChunks; i++) {
      const start = i * CHUNK_SIZE
      const end = Math.min(start + CHUNK_SIZE, task.file.size)
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
      task.status = 'pending'
      task.progress = 0
      task.error = undefined
      processQueue()
    }
  }

  function remove(taskId: string) {
    tasks.value = tasks.value.filter(t => t.id !== taskId)
  }

  const pendingRefreshDirs = new Set<string>()

  const flushRefresh = useDebounceFn(() => {
    const navigation = useNavigationStore()
    const fileList = useFileListStore()
    const tree = useTreeStore()

    const dirs = new Set(pendingRefreshDirs)
    pendingRefreshDirs.clear()

    for (const dir of dirs) {
      tree.invalidate(dir)
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
    hasActive,
    show,
    hide,
    addFiles,
    addFolderFiles,
    addFilesWithPaths,
    retry,
    remove,
  }
})
