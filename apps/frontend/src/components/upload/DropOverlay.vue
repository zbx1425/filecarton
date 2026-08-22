<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useUploadStore } from '@/stores/upload'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import { Upload } from '@lucide/vue'

const upload = useUploadStore()
const navigation = useNavigationStore()
const ui = useUiStore()

const showOverlay = ref(false)
let dragCounter = 0
let safetyTimer: ReturnType<typeof setTimeout> | null = null

function syncOverlay(visible: boolean) {
  showOverlay.value = visible
  ui.dragOverlayVisible = visible
  if (visible) {
    resetSafetyTimer()
  } else if (safetyTimer) {
    clearTimeout(safetyTimer)
    safetyTimer = null
  }
}

function resetSafetyTimer() {
  if (safetyTimer) clearTimeout(safetyTimer)
  safetyTimer = setTimeout(() => {
    if (showOverlay.value) {
      dragCounter = 0
      syncOverlay(false)
    }
  }, 5000)
}

function isExternalFileDrag(e: DragEvent): boolean {
  return e.dataTransfer?.types.includes('Files') === true
    && !e.dataTransfer?.types.includes('application/filecarton')
}

function handleDragEnter(e: DragEvent) {
  if (ui.readonly) return
  if (!isExternalFileDrag(e)) return
  dragCounter++
  if (dragCounter === 1) syncOverlay(true)
}

function handleDragLeave(_e: DragEvent) {
  dragCounter--
  if (dragCounter <= 0) {
    dragCounter = 0
    syncOverlay(false)
  }
}

function handleDragOver(e: DragEvent) {
  if (!isExternalFileDrag(e)) return
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'
  if (showOverlay.value) resetSafetyTimer()
}

function handleDragEnd() {
  if (showOverlay.value) {
    dragCounter = 0
    syncOverlay(false)
  }
}

function handleKeyDown(e: KeyboardEvent) {
  if (e.key === 'Escape' && showOverlay.value) {
    dragCounter = 0
    syncOverlay(false)
    e.stopPropagation()
  }
}

async function readEntryRecursive(
  entry: FileSystemEntry,
  basePath: string,
): Promise<{ file: File; relativePath: string }[]> {
  if (entry.isFile) {
    return new Promise((resolve, reject) => {
      ;(entry as FileSystemFileEntry).file(
        (f) => resolve([{ file: f, relativePath: basePath ? `${basePath}/${f.name}` : f.name }]),
        reject,
      )
    })
  }
  if (entry.isDirectory) {
    const dirReader = (entry as FileSystemDirectoryEntry).createReader()
    const results: { file: File; relativePath: string }[] = []
    const dirPath = basePath ? `${basePath}/${entry.name}` : entry.name

    let batch: FileSystemEntry[] = []
    do {
      batch = await new Promise<FileSystemEntry[]>((resolve, reject) => {
        dirReader.readEntries(resolve, reject)
      })
      for (const child of batch) {
        results.push(...(await readEntryRecursive(child, dirPath)))
      }
    } while (batch.length > 0)

    return results
  }
  return []
}

async function handleDrop(e: DragEvent) {
  e.preventDefault()
  dragCounter = 0
  syncOverlay(false)

  if (ui.readonly) return

  const items = e.dataTransfer?.items
  if (!items || items.length === 0) return

  const entries: FileSystemEntry[] = []
  for (let i = 0; i < items.length; i++) {
    const entry = items[i].webkitGetAsEntry?.()
    if (entry) entries.push(entry)
  }

  if (entries.length === 0) {
    const files = e.dataTransfer?.files
    if (files && files.length > 0) {
      upload.addFiles(files, navigation.currentPathStr)
    }
    return
  }

  const allFiles: { file: File; relativePath: string }[] = []
  for (const entry of entries) {
    allFiles.push(...(await readEntryRecursive(entry, '')))
  }

  if (allFiles.length > 0) {
    upload.addFilesWithPaths(allFiles, navigation.currentPathStr)
  }
}

onMounted(() => {
  document.addEventListener('dragenter', handleDragEnter)
  document.addEventListener('dragleave', handleDragLeave)
  document.addEventListener('dragover', handleDragOver)
  document.addEventListener('drop', handleDrop)
  document.addEventListener('dragend', handleDragEnd)
  document.addEventListener('keydown', handleKeyDown)
})

onBeforeUnmount(() => {
  document.removeEventListener('dragenter', handleDragEnter)
  document.removeEventListener('dragleave', handleDragLeave)
  document.removeEventListener('dragover', handleDragOver)
  document.removeEventListener('drop', handleDrop)
  document.removeEventListener('dragend', handleDragEnd)
  document.removeEventListener('keydown', handleKeyDown)
  if (safetyTimer) clearTimeout(safetyTimer)
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="showOverlay"
      class="fixed inset-0 z-50 bg-primary/10 border-4 border-dashed border-primary flex items-center justify-center pointer-events-none"
    >
      <div class="flex flex-col items-center gap-3 text-primary">
        <Upload class="size-12" />
        <p class="text-lg font-medium">
          Drop to upload to /{{ navigation.currentPathStr || '(root)' }}
        </p>
      </div>
    </div>
  </Teleport>
</template>
