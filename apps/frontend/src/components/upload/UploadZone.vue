<script setup lang="ts">
import { ref } from 'vue'
import { Button } from '@/components/ui/button'
import { Progress } from '@/components/ui/progress'
import { useUploadStore } from '@/stores/upload'
import { useNavigationStore } from '@/stores/navigation'
import { formatSize } from '@/utils/format'
import {
  Upload, FolderUp, X, RotateCcw, Loader2, CheckCircle2, XCircle,
} from '@lucide/vue'

const upload = useUploadStore()
const navigation = useNavigationStore()

const fileInputRef = ref<HTMLInputElement | null>(null)
const folderInputRef = ref<HTMLInputElement | null>(null)

function openFilePicker() {
  fileInputRef.value?.click()
}

function openFolderPicker() {
  folderInputRef.value?.click()
}

function handleFileSelect(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files && input.files.length > 0) {
    upload.addFiles(input.files, navigation.currentPathStr)
    input.value = ''
  }
}

function handleFolderSelect(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files && input.files.length > 0) {
    upload.addFolderFiles(input.files, navigation.currentPathStr)
    input.value = ''
  }
}
</script>

<template>
  <div v-if="upload.visible" class="border-b">
    <div class="flex items-center px-3 py-2 gap-2">
      <span class="text-xs font-medium flex-1">Upload</span>
      <Button variant="outline" size="xs" @click="openFilePicker">
        <Upload class="size-3.5" />
        Files
      </Button>
      <Button variant="outline" size="xs" @click="openFolderPicker">
        <FolderUp class="size-3.5" />
        Folder
      </Button>
      <Button variant="ghost" size="icon-xs" @click="upload.hide()">
        <X class="size-3.5" />
      </Button>
    </div>

    <div v-if="upload.tasks.length > 0" class="max-h-40 overflow-auto divide-y border-t">
      <div
        v-for="task in upload.tasks"
        :key="task.id"
        class="flex items-center px-3 py-1.5 gap-2 text-xs"
      >
        <Loader2 v-if="task.status === 'uploading'" class="size-3.5 animate-spin text-primary shrink-0" />
        <CheckCircle2 v-else-if="task.status === 'success'" class="size-3.5 text-green-600 shrink-0" />
        <XCircle v-else-if="task.status === 'error'" class="size-3.5 text-destructive shrink-0" />
        <Upload v-else class="size-3.5 text-muted-foreground shrink-0" />

        <span class="truncate flex-1">{{ task.file.name }}</span>
        <span class="text-muted-foreground shrink-0 tabular-nums">{{ formatSize(task.file.size) }}</span>

        <div v-if="task.status === 'uploading'" class="w-16 shrink-0">
          <Progress :model-value="task.progress" class="h-1" />
        </div>

        <Button
          v-if="task.status === 'error'"
          variant="ghost"
          size="icon-xs"
          @click="upload.retry(task.id)"
        >
          <RotateCcw class="size-3" />
        </Button>

        <Button
          variant="ghost"
          size="icon-xs"
          @click="upload.remove(task.id)"
        >
          <X class="size-3" />
        </Button>
      </div>
    </div>

    <input ref="fileInputRef" type="file" multiple class="hidden" @change="handleFileSelect" />
    <input ref="folderInputRef" type="file" class="hidden" webkitdirectory @change="handleFolderSelect" />
  </div>
</template>
