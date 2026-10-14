<script setup lang="ts">
import { FileArchive, Download, PackageOpen, ChevronDown } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import type { ErrorInfo } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import { formatSize } from '@/utils/format'
import { archiveSubdirName } from '@/utils/path'

defineProps<{
  archiveName: string
  size?: number
  error: ErrorInfo
}>()

const emit = defineEmits<{
  downloadArchive: []
  extract: [createSubdir: boolean]
}>()

const ui = useUiStore()
</script>

<template>
  <div class="flex-1 flex flex-col items-center justify-center p-8 gap-4 text-center">
    <FileArchive class="size-16 text-muted-foreground" />
    <p class="text-sm font-mono">{{ archiveName }}</p>
    <p v-if="size" class="text-xs text-muted-foreground">{{ formatSize(size) }}</p>
    <p class="text-sm text-destructive max-w-md">
      {{ $t('err.' + error.code, error.params ?? {}) }}
    </p>
    <div class="flex items-center gap-2 mt-1">
      <Button variant="outline" size="sm" @click="emit('downloadArchive')">
        <Download class="size-4" />
        Download Archive
      </Button>
      <DropdownMenu v-if="!ui.readonly && ui.features.archive">
        <DropdownMenuTrigger as-child>
          <Button variant="outline" size="sm">
            <PackageOpen class="size-4" />
            Extract
            <ChevronDown class="size-3.5" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="center">
          <DropdownMenuItem @select="emit('extract', false)">
            Extract Here
          </DropdownMenuItem>
          <DropdownMenuItem @select="emit('extract', true)">
            Extract to "{{ archiveSubdirName(archiveName) }}/"
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  </div>
</template>
