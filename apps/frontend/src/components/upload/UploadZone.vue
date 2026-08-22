<script setup lang="ts">
import { ref } from 'vue'
import { Button } from '@/components/ui/button'
import { useUploadStore } from '@/stores/upload'
import { useNavigationStore } from '@/stores/navigation'
import { Upload, FolderUp, X } from '@lucide/vue'

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
      <Button variant="outline" size="sm" @click="openFilePicker">
        <Upload class="size-4" />
        Files
      </Button>
      <Button variant="outline" size="sm" @click="openFolderPicker">
        <FolderUp class="size-4" />
        Folder
      </Button>
      <Button variant="ghost" size="icon-sm" @click="upload.hide()">
        <X class="size-4" />
      </Button>
    </div>

    <input ref="fileInputRef" type="file" multiple class="hidden" @change="handleFileSelect" />
    <input ref="folderInputRef" type="file" class="hidden" webkitdirectory @change="handleFolderSelect" />
  </div>
</template>
