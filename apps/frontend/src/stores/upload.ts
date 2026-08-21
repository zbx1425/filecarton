import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { toast } from 'vue-sonner'
import { apiUpload, apiPost } from '@/api/client'
import type { UploadResponse, UploadChunkResponse, UploadCompleteResponse } from '@/api/types'
import { useFileListStore } from '@/stores/fileList'
import { useNavigationStore } from '@/stores/navigation'
import { useTreeStore } from '@/stores/tree'
import { joinPath } from '@/utils/path'
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

  const activeTasks = computed(() => tasks.value.filter(t => t.status === 'uploading').length)
  const hasActive = computed(() => tasks.value.some(t => t.status === 'uploading' || t.status === 'pending'))

  function show() { visible.value = true }
  function hide() { visible.value = false }

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
      const succeeded = tasks.value.filter(t => t.status === 'success').length
      const failed = tasks.value.filter(t => t.status === 'error').length
      if (failed > 0) {
        toast.error(`Upload complete: ${succeeded} succeeded, ${failed} failed`)
      } else if (succeeded > 0) {
        toast.success(`Uploaded ${succeeded} file(s)`)
      }
      setTimeout(() => {
        if (!hasActive.value) {
          tasks.value = tasks.value.filter(t => t.status !== 'success')
          if (tasks.value.length === 0) visible.value = false
        }
      }, 2000)
    }
  }

  async function uploadSingle(task: UploadTask) {
    const formData = new FormData()
    formData.append('path', task.targetDir)

    if (task.relativePath) {
      const dir = task.relativePath.split('/').slice(0, -1).join('/')
      formData.append('path', joinPath(task.targetDir, dir))
    }

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

  function refreshAfterUpload(targetDir: string) {
    const navigation = useNavigationStore()
    const fileList = useFileListStore()
    const tree = useTreeStore()
    if (navigation.currentPathStr === targetDir) {
      fileList.fetchDir(targetDir)
    }
    tree.invalidate(targetDir)
    tree.loadChildren(targetDir)
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
    retry,
    remove,
  }
})
