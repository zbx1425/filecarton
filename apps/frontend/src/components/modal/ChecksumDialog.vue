<script setup lang="ts">
import { computed, ref } from 'vue'
import { toast } from 'vue-sonner'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Copy, Check } from '@lucide/vue'
import { dialogState } from '@/composables/useDialogs'
import { formatSizeExact } from '@/utils/format'

const state = computed(() => dialogState.checksum)

interface Row {
  label: string
  value: string
  copyValue: string
  mono?: boolean
}

const rows = computed<Row[]>(() => [
  { label: 'Name', value: state.value.name, copyValue: state.value.name },
  { label: 'Size', value: formatSizeExact(state.value.size), copyValue: formatSizeExact(state.value.size) },
  { label: 'SHA256', value: state.value.sha256, copyValue: state.value.sha256, mono: true },
  { label: 'SHA1', value: state.value.sha1, copyValue: state.value.sha1, mono: true },
  { label: 'MD5', value: state.value.md5, copyValue: state.value.md5, mono: true },
])

const copiedKey = ref('')
let copiedTimer: ReturnType<typeof setTimeout> | null = null

async function copyValue(label: string, value: string) {
  try {
    await navigator.clipboard.writeText(value)
    copiedKey.value = label
    if (copiedTimer) clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => { copiedKey.value = '' }, 1500)
  } catch {
    toast.error('Failed to copy to clipboard')
  }
}

function closeDialog() {
  dialogState.checksum.open = false
  copiedKey.value = ''
}
</script>

<template>
  <Dialog :open="state.open" @update:open="(v: boolean) => { if (!v) closeDialog() }">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>Checksum</DialogTitle>
        <DialogDescription class="sr-only">File checksum information</DialogDescription>
      </DialogHeader>

      <div class="rounded-md border divide-y">
        <div
          v-for="row in rows"
          :key="row.label"
          class="flex items-start gap-3 px-3 py-2.5"
        >
          <span class="text-muted-foreground text-sm font-medium w-16 shrink-0 pt-px">
            {{ row.label }}
          </span>
          <span
            class="text-sm flex-1 min-w-0 select-all break-all pt-px"
            :class="row.mono ? 'font-mono text-xs leading-5' : ''"
          >
            {{ row.value }}
          </span>
          <Button
            variant="ghost"
            size="icon-xs"
            class="shrink-0 text-muted-foreground"
            :title="copiedKey === row.label ? 'Copied!' : 'Copy'"
            @click="copyValue(row.label, row.copyValue)"
          >
            <Check v-if="copiedKey === row.label" class="text-green-500" />
            <Copy v-else />
          </Button>
        </div>
      </div>

      <DialogFooter>
        <Button @click="closeDialog">Close</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
