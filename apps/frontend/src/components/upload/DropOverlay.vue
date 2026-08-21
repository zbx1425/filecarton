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

function isExternalFileDrag(e: DragEvent): boolean {
  return e.dataTransfer?.types.includes('Files') === true
    && !e.dataTransfer?.types.includes('application/filecarton')
}

function handleDragEnter(e: DragEvent) {
  if (ui.readonly) return
  if (!isExternalFileDrag(e)) return
  dragCounter++
  if (dragCounter === 1) showOverlay.value = true
}

function handleDragLeave(_e: DragEvent) {
  dragCounter--
  if (dragCounter <= 0) {
    dragCounter = 0
    showOverlay.value = false
  }
}

function handleDragOver(e: DragEvent) {
  if (!isExternalFileDrag(e)) return
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'
}

function handleDrop(e: DragEvent) {
  e.preventDefault()
  dragCounter = 0
  showOverlay.value = false

  if (ui.readonly) return

  const files = e.dataTransfer?.files
  if (files && files.length > 0) {
    upload.addFiles(files, navigation.currentPathStr)
  }
}

onMounted(() => {
  document.addEventListener('dragenter', handleDragEnter)
  document.addEventListener('dragleave', handleDragLeave)
  document.addEventListener('dragover', handleDragOver)
  document.addEventListener('drop', handleDrop)
})

onBeforeUnmount(() => {
  document.removeEventListener('dragenter', handleDragEnter)
  document.removeEventListener('dragleave', handleDragLeave)
  document.removeEventListener('dragover', handleDragOver)
  document.removeEventListener('drop', handleDrop)
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
