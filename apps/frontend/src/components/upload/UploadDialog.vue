<script setup lang="ts">
import { ref } from 'vue'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useUploadStore } from '@/stores/upload'
import { useNavigationStore } from '@/stores/navigation'
import { Upload, File, FolderOpen } from '@lucide/vue'

const upload = useUploadStore()
const navigation = useNavigationStore()

const fileInputRef = ref<HTMLInputElement | null>(null)
const folderInputRef = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)
let dragCounter = 0

function handleOpenChange(open: boolean) {
  if (!open) upload.closeUploadDialog()
}

function handleFileSelect(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files && input.files.length > 0) {
    upload.addFiles(input.files, navigation.currentPathStr)
    upload.closeUploadDialog()
    input.value = ''
  }
}

function handleFolderSelect(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files && input.files.length > 0) {
    upload.addFolderFiles(input.files, navigation.currentPathStr)
    upload.closeUploadDialog()
    input.value = ''
  }
}

function handleDragEnter(e: DragEvent) {
  if (!e.dataTransfer?.types.includes('Files')) return
  e.preventDefault()
  dragCounter++
  if (dragCounter === 1) isDragging.value = true
}

function handleDragLeave() {
  dragCounter--
  if (dragCounter <= 0) {
    dragCounter = 0
    isDragging.value = false
  }
}

function handleDragOver(e: DragEvent) {
  if (!e.dataTransfer?.types.includes('Files')) return
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'
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
  isDragging.value = false

  const items = e.dataTransfer?.items
  if (!items || items.length === 0) return

  const entries: FileSystemEntry[] = []
  for (let i = 0; i < items.length; i++) {
    const entry = items[i].webkitGetAsEntry?.()
    if (entry) entries.push(entry)
  }

  upload.closeUploadDialog()

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
</script>

<template>
  <Dialog :open="upload.uploadDialogOpen" @update:open="handleOpenChange">
    <DialogContent class="max-w-md" :show-close-button="true">
      <DialogHeader>
        <DialogTitle>Upload</DialogTitle>
        <DialogDescription>
          Upload to <span class="font-mono text-foreground">/{{ navigation.currentPathStr || '' }}</span>
        </DialogDescription>
      </DialogHeader>

      <div
        class="drop-zone group relative flex flex-col items-center justify-center rounded-lg border-2 border-dashed py-10 px-6 transition-all duration-200 cursor-default"
        :class="isDragging
          ? 'border-primary bg-primary/8 scale-[1.01]'
          : 'border-border/70 hover:border-primary/40 hover:bg-muted/30'"
        @dragenter="handleDragEnter"
        @dragleave="handleDragLeave"
        @dragover="handleDragOver"
        @drop="handleDrop"
      >
        <div
          class="rounded-full p-3 mb-3 transition-colors duration-200"
          :class="isDragging ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground group-hover:bg-primary/10 group-hover:text-primary/70'"
        >
          <Upload class="size-6" />
        </div>

        <p class="text-sm font-medium mb-1" :class="isDragging ? 'text-primary' : 'text-foreground'">
          {{ isDragging ? 'Release to upload' : 'Drag and drop files here' }}
        </p>
        <p class="text-xs text-muted-foreground">
          or use the buttons below
        </p>
      </div>

      <div class="flex gap-2">
        <Button variant="outline" class="flex-1" @click="fileInputRef?.click()">
          <File class="size-4 mr-1.5" />
          Select Files
        </Button>
        <Button variant="outline" class="flex-1" @click="folderInputRef?.click()">
          <FolderOpen class="size-4 mr-1.5" />
          Select Folder
        </Button>
      </div>

      <input ref="fileInputRef" type="file" multiple class="hidden" @change="handleFileSelect" />
      <input ref="folderInputRef" type="file" class="hidden" webkitdirectory @change="handleFolderSelect" />
    </DialogContent>
  </Dialog>
</template>
