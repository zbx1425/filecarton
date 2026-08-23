<script setup lang="ts">
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { ScrollArea } from '@/components/ui/scroll-area'
import { useUploadStore } from '@/stores/upload'
import { formatSize } from '@/utils/format'
import { RotateCcw, Trash2, AlertTriangle } from '@lucide/vue'

const upload = useUploadStore()

function handleOpenChange(open: boolean) {
  if (!open) upload.closeErrorDialog()
}
</script>

<template>
  <Dialog :open="upload.errorDialogOpen" @update:open="handleOpenChange">
    <DialogContent class="!max-w-2xl">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          <AlertTriangle class="size-5 text-destructive" />
          Upload Failures
        </DialogTitle>
        <DialogDescription>
          {{ upload.errorTasks.length }} file(s) failed to upload.
        </DialogDescription>
      </DialogHeader>

      <ScrollArea class="max-h-[320px] min-w-0 -mx-1 px-1 error-scroll">
        <div class="space-y-1">
          <div
            v-for="task in upload.errorTasks"
            :key="task.id"
            class="flex items-start gap-2 rounded-md border border-border/60 p-2 text-xs"
          >
            <AlertTriangle class="size-3.5 text-destructive shrink-0 mt-0.5" />
            <div class="flex-1 min-w-0 overflow-hidden">
              <div class="font-medium truncate" :title="task.relativePath || task.file.name">
                {{ task.relativePath || task.file.name }}
              </div>
              <div class="text-muted-foreground mt-0.5 break-words">
                {{ formatSize(task.file.size) }}
                <span v-if="task.error" class="text-destructive"> &mdash; {{ task.error }}</span>
              </div>
            </div>
            <div class="flex items-center gap-0.5 shrink-0">
              <Button
                variant="ghost"
                size="icon-xs"
                class="size-6"
                title="Retry"
                @click="upload.retry(task.id)"
              >
                <RotateCcw class="size-3" />
              </Button>
              <Button
                variant="ghost"
                size="icon-xs"
                class="size-6 text-destructive hover:text-destructive"
                title="Remove"
                @click="upload.remove(task.id)"
              >
                <Trash2 class="size-3" />
              </Button>
            </div>
          </div>
        </div>
      </ScrollArea>

      <DialogFooter class="mt-2 gap-2">
        <Button
          v-if="upload.errorTasks.length > 1"
          variant="outline"
          size="sm"
          @click="upload.retryAllErrors()"
        >
          <RotateCcw class="size-3.5 mr-1.5" />
          Retry All
        </Button>
        <Button
          variant="destructive"
          size="sm"
          @click="upload.clearErrors()"
        >
          <Trash2 class="size-3.5 mr-1.5" />
          Clear All
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>

<style scoped>
.error-scroll :deep([data-slot="scroll-area-viewport"] > div) {
  display: block !important;
  min-width: 0 !important;
}
</style>
