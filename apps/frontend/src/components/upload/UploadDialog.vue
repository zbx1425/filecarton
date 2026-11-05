<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
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
import { readEntryRecursive } from '@/utils/fileEntry'
import { toast } from 'vue-sonner'

const { t } = useI18n()
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

async function handleDrop(e: DragEvent) {
  e.preventDefault()
  e.stopPropagation()
  dragCounter = 0
  isDragging.value = false

  const items = e.dataTransfer?.items
  if (!items || items.length === 0) return

  const entries: FileSystemEntry[] = []
  for (let i = 0; i < items.length; i++) {
    const entry = items[i].webkitGetAsEntry?.()
    if (entry) entries.push(entry)
  }

  const targetDir = navigation.currentPathStr
  upload.closeUploadDialog()

  if (entries.length === 0) {
    const files = e.dataTransfer?.files
    if (files && files.length > 0) {
      upload.addFiles(files, targetDir)
    }
    return
  }

  const allFiles: { file: File; relativePath: string }[] = []
  let readErrors = 0
  for (const entry of entries) {
    try {
      allFiles.push(...(await readEntryRecursive(entry, '')))
    } catch {
      readErrors++
    }
  }

  if (readErrors > 0 && allFiles.length === 0) {
    toast.error(t('upload.read_failed'))
    return
  }
  if (readErrors > 0) {
    toast.warning(t('upload.read_skipped', { count: readErrors }, readErrors))
  }
  if (allFiles.length > 0) {
    upload.addFilesWithPaths(allFiles, targetDir)
  }
}
</script>

<template>
  <Dialog :open="upload.uploadDialogOpen" @update:open="handleOpenChange">
    <DialogContent class="max-w-md" :show-close-button="true">
      <DialogHeader>
        <DialogTitle>{{ $t('common.action.upload') }}</DialogTitle>
        <DialogDescription>
          <i18n-t keypath="upload.to">
            <template #path>
              <span class="font-mono text-foreground">/{{ navigation.currentPathStr || '' }}</span>
            </template>
          </i18n-t>
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
          {{ isDragging ? $t('upload.release') : $t('upload.drop') }}
        </p>
        <p class="text-sm text-muted-foreground">
          {{ $t('upload.or_buttons') }}
        </p>
      </div>

      <div class="flex gap-2">
        <Button variant="outline" class="flex-1" @click="fileInputRef?.click()">
          <File class="size-4 mr-1.5" />
          {{ $t('upload.select_files') }}
        </Button>
        <Button variant="outline" class="flex-1" @click="folderInputRef?.click()">
          <FolderOpen class="size-4 mr-1.5" />
          {{ $t('upload.select_folder') }}
        </Button>
      </div>

      <input ref="fileInputRef" type="file" multiple class="hidden" @change="handleFileSelect" />
      <input ref="folderInputRef" type="file" class="hidden" webkitdirectory @change="handleFolderSelect" />
    </DialogContent>
  </Dialog>
</template>
