<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
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
import { Label } from '@/components/ui/label'
import { Link, Info } from '@lucide/vue'
import { dialogState } from '@/composables/useDialogs'
import { usePreferencesStore } from '@/stores/preferences'
import { validateFileName } from '@/utils/path'
import { remoteDownload } from '@/composables/useFileActions'

const prefs = usePreferencesStore()

const state = computed(() => dialogState.remoteDownload)
const urlInput = ref('')
const nameInput = ref('')
const urlInputRef = ref<InstanceType<typeof Input> | null>(null)

watch(() => state.value.open, (open) => {
  if (open) {
    urlInput.value = ''
    nameInput.value = ''
    nextTick(() => {
      const el = urlInputRef.value?.$el as HTMLInputElement | undefined
      el?.focus()
    })
  }
})

const parsedUrl = computed<URL | null>(() => {
  const trimmed = urlInput.value.trim()
  if (!trimmed) return null
  try {
    const u = new URL(trimmed)
    if ((u.protocol === 'http:' || u.protocol === 'https:') && u.hostname) {
      return u
    }
    return null
  } catch {
    return null
  }
})

const isUrlValid = computed(() => parsedUrl.value !== null)

const urlError = computed(() => {
  const trimmed = urlInput.value.trim()
  if (!trimmed) return ''
  if (!isUrlValid.value) return 'Please enter a valid HTTP or HTTPS URL'
  return ''
})

const nameError = computed(() => {
  const custom = nameInput.value.trim()
  if (!custom) return ''

  const vErr = validateFileName(custom)
  if (vErr) return vErr
  if (prefs.isDotFileBlocked(custom)) {
    return 'Dot files (hidden files) are not allowed'
  }
  if (prefs.isFilenameBlocked(custom)) {
    return 'This file type is restricted'
  }
  return ''
})

const canSubmit = computed(() => isUrlValid.value && !nameError.value)

function closeDialog() {
  state.value.open = false
}

function handleSubmit() {
  if (!canSubmit.value) return
  const targetDir = state.value.targetDir
  const url = urlInput.value.trim()
  const customName = nameInput.value.trim()
  closeDialog()
  remoteDownload(targetDir, url, customName || undefined)
}
</script>

<template>
  <Dialog :open="state.open" @update:open="(v: boolean) => { if (!v) closeDialog() }">
    <DialogContent class="sm:max-w-md">
      <form @submit.prevent="handleSubmit">
        <DialogHeader>
          <DialogTitle>Remote Download</DialogTitle>
          <DialogDescription>
            To <span class="font-mono text-foreground">/{{ state.targetDir || '' }}</span>
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-4 py-4">
          <!-- URL Field -->
          <div class="space-y-1.5">
            <Label for="fc-remote-url" class="text-sm font-medium">URL</Label>
            <div class="relative">
              <Link class="absolute left-2.5 top-1/2 -translate-y-1/2 size-4 text-muted-foreground pointer-events-none" />
              <Input
                id="fc-remote-url"
                ref="urlInputRef"
                v-model="urlInput"
                type="url"
                class="pl-8 font-mono text-sm"
                autocomplete="off"
                spellcheck="false"
              />
            </div>
            <p v-if="urlError" class="text-sm text-destructive">
              {{ urlError }}
            </p>
          </div>

          <!-- Target Filename Field -->
          <div class="space-y-1.5">
            <Label for="fc-remote-name" class="text-sm font-medium">Save as 
              <span class="text-muted-foreground">(Optional)</span>
            </Label>
            <Input
              id="fc-remote-name"
              v-model="nameInput"
              placeholder="(Auto-detect)"
              class="text-sm"
              autocomplete="off"
              spellcheck="false"
            />
            <p class="text-sm leading-4 min-h-4 text-destructive">
              {{ nameError }}
            </p>
          </div>

          <!-- Timeout / Gateway Notice -->
          <div class="flex items-start gap-2.5 rounded-md border bg-muted/40 px-3 py-2.5 text-sm text-muted-foreground leading-relaxed">
            <Info class="size-4 shrink-0 mt-0.5 text-muted-foreground" />
            <span>
              The file is fetched directly by the server. <br/>
              Downloads that exceed PHP's execution time limit or your web server / proxy timeout may fail.
            </span>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" type="button" @click="closeDialog">
            Cancel
          </Button>
          <Button type="submit" :disabled="!canSubmit">
            Download
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
