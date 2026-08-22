<script setup lang="ts">
import { ref } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { useClipboardStore } from '@/stores/clipboard'
import { useNavigationStore } from '@/stores/navigation'
import { pasteItems } from '@/composables/useFileActions'
import { Copy, Scissors, ClipboardPaste } from '@lucide/vue'

const clipboard = useClipboardStore()
const navigation = useNavigationStore()
const popoverOpen = ref(false)

const MAX_DISPLAY = 10

async function handlePaste() {
  popoverOpen.value = false
  await pasteItems(navigation.currentPathStr)
}

function handleClear() {
  popoverOpen.value = false
  clipboard.clear()
}
</script>

<template>
  <Popover v-model:open="popoverOpen">
    <PopoverTrigger as-child>
      <Badge
        v-if="clipboard.hasContent"
        variant="secondary"
        class="cursor-pointer gap-1 whitespace-nowrap mx-1"
      >
        <Scissors v-if="clipboard.mode === 'cut'" class="size-3" />
        <Copy v-else class="size-3" />
        {{ clipboard.itemCount }}
      </Badge>
    </PopoverTrigger>
    <PopoverContent class="w-64" align="end">
      <div class="text-sm font-medium mb-1">
        Clipboard ({{ clipboard.mode === 'cut' ? 'Cut' : 'Copy' }})
      </div>
      <div class="text-xs text-muted-foreground mb-2">
        From: /{{ clipboard.sourcePath || '(root)' }}
      </div>
      <Separator class="mb-2" />
      <div class="space-y-0.5 text-xs max-h-40 overflow-auto">
        <div
          v-for="item in clipboard.items.slice(0, MAX_DISPLAY)"
          :key="item.name"
          class="truncate"
        >
          {{ item.name }}{{ item.isDir ? '/' : '' }}
        </div>
        <div v-if="clipboard.items.length > MAX_DISPLAY" class="text-muted-foreground">
          and {{ clipboard.items.length - MAX_DISPLAY }} more...
        </div>
      </div>
      <Separator class="my-2" />
      <div class="flex items-center gap-2">
        <Button size="sm" class="flex-1" @click="handlePaste">
          <ClipboardPaste class="size-3.5" />
          Paste Here
        </Button>
        <Button variant="ghost" size="sm" @click="handleClear">Clear</Button>
      </div>
    </PopoverContent>
  </Popover>
</template>
