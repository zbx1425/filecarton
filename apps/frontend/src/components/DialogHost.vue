<script setup lang="ts">
import { ref, watch, nextTick } from 'vue'
import { dialogState } from '@/composables/useDialogs'
import {
  AlertDialog,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { pathWithoutExtension } from '@/utils/path'

const promptValue = ref('')
const promptInputRef = ref<InstanceType<typeof Input> | null>(null)
const confirmBtnRef = ref<HTMLElement | null>(null)

function handleConfirmAutoFocus(e: Event) {
  e.preventDefault()
  nextTick(() => confirmBtnRef.value?.focus())
}

watch(() => dialogState.prompt.open, (open) => {
  if (open) {
    promptValue.value = dialogState.prompt.initialValue
    nextTick(() => {
      const input = promptInputRef.value?.$el as HTMLInputElement | undefined
      if (input) {
        input.focus()
        if (dialogState.prompt.selectBaseName && dialogState.prompt.initialValue) {
          const baseName = pathWithoutExtension(dialogState.prompt.initialValue)
          input.setSelectionRange(0, baseName.length)
        } else {
          input.select()
        }
      }
    })
  }
})

function resolveConfirm(value: boolean) {
  dialogState.confirm.resolve?.(value)
  dialogState.confirm.open = false
  dialogState.confirm.resolve = null
}

function resolvePrompt(value: string | null) {
  dialogState.prompt.resolve?.(value)
  dialogState.prompt.open = false
  dialogState.prompt.resolve = null
}

function resolvePasteConflict(value: boolean) {
  dialogState.pasteConflict.resolve?.(value)
  dialogState.pasteConflict.open = false
  dialogState.pasteConflict.resolve = null
}

function resolveEditorConflict(value: 'overwrite' | 'reload' | 'cancel') {
  dialogState.editorConflict.resolve?.(value)
  dialogState.editorConflict.open = false
  dialogState.editorConflict.resolve = null
}

function handlePromptSubmit() {
  const val = promptValue.value.trim()
  if (val) resolvePrompt(val)
}

function handlePromptOpenChange(open: boolean) {
  if (!open) resolvePrompt(null)
}

function handlePasteConflictOpenChange(open: boolean) {
  if (!open) resolvePasteConflict(false)
}

function handleEditorConflictOpenChange(open: boolean) {
  if (!open) resolveEditorConflict('cancel')
}
</script>

<template>
  <!-- Confirm (AlertDialog) -->
  <AlertDialog :open="dialogState.confirm.open">
    <AlertDialogContent @escapeKeyDown="resolveConfirm(false)" @openAutoFocus="handleConfirmAutoFocus">
      <form @submit.prevent="resolveConfirm(true)">
        <AlertDialogHeader>
          <AlertDialogTitle>{{ dialogState.confirm.title }}</AlertDialogTitle>
          <AlertDialogDescription>{{ dialogState.confirm.message }}</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter class="mt-4">
          <Button variant="outline" type="button" @click="resolveConfirm(false)">Cancel</Button>
          <Button
            ref="confirmBtnRef"
            type="submit"
            :class="dialogState.confirm.danger ? 'bg-destructive text-white hover:bg-destructive/90' : ''"
          >
            {{ dialogState.confirm.actionLabel }}
          </Button>
        </AlertDialogFooter>
      </form>
    </AlertDialogContent>
  </AlertDialog>

  <!-- Prompt (Dialog) -->
  <Dialog :open="dialogState.prompt.open" @update:open="handlePromptOpenChange">
      <DialogContent>
      <DialogHeader>
        <DialogTitle>{{ dialogState.prompt.title }}</DialogTitle>
        <DialogDescription class="sr-only">Enter a value</DialogDescription>
      </DialogHeader>
      <form @submit.prevent="handlePromptSubmit">
        <Input
          ref="promptInputRef"
          v-model="promptValue"
          :placeholder="dialogState.prompt.placeholder"
          class="mt-2"
        />
        <DialogFooter class="mt-4">
          <Button variant="outline" type="button" @click="resolvePrompt(null)">Cancel</Button>
          <Button type="submit" :disabled="!promptValue.trim()">{{ dialogState.prompt.submitLabel }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>

  <!-- Paste Conflict (Dialog) -->
  <Dialog :open="dialogState.pasteConflict.open" @update:open="handlePasteConflictOpenChange">
    <DialogContent>
      <form @submit.prevent="resolvePasteConflict(true)">
        <DialogHeader>
          <DialogTitle>File Conflict</DialogTitle>
          <DialogDescription>
            The following {{ dialogState.pasteConflict.files.length }} file(s) already exist in the target directory:
          </DialogDescription>
        </DialogHeader>
        <div v-if="dialogState.pasteConflict.activeFilePath && dialogState.pasteConflict.files.includes(dialogState.pasteConflict.activeFilePath)" class="text-xs text-destructive mt-2 px-1">
          Warning: One of these files is currently open in the editor. Overwriting will discard your changes.
        </div>
        <div class="max-h-48 overflow-auto border p-2 text-xs font-mono space-y-0.5 mt-2">
          <div
            v-for="f in dialogState.pasteConflict.files"
            :key="f"
            :class="f === dialogState.pasteConflict.activeFilePath ? 'text-destructive font-medium' : ''"
          >
            {{ f }}
            <span v-if="f === dialogState.pasteConflict.activeFilePath" class="text-destructive opacity-70">(editing)</span>
          </div>
        </div>
        <DialogFooter class="mt-4">
          <Button variant="outline" type="button" @click="resolvePasteConflict(false)">Cancel</Button>
          <Button variant="destructive" type="submit">Overwrite All</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>

  <!-- Editor Conflict (Dialog) -->
  <Dialog :open="dialogState.editorConflict.open" @update:open="handleEditorConflictOpenChange">
    <DialogContent>
      <form @submit.prevent="resolveEditorConflict('overwrite')">
        <DialogHeader>
          <DialogTitle>File Modified</DialogTitle>
          <DialogDescription>
            This file was modified since you opened it.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter class="mt-4">
          <Button variant="outline" type="button" @click="resolveEditorConflict('cancel')">Cancel</Button>
          <Button variant="secondary" type="button" @click="resolveEditorConflict('reload')">Reload</Button>
          <Button type="submit">Overwrite</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
