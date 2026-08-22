<script setup lang="ts">
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useFileListStore } from '@/stores/fileList'
import { useUiStore } from '@/stores/ui'
import { copySelected, cutSelected, deleteSelected } from '@/composables/useFileActions'
import { createArchive } from '@/composables/useArchive'
import { Copy, Scissors, Trash2, X, FileArchive } from '@lucide/vue'

const fileList = useFileListStore()
const ui = useUiStore()

function handleSelectAll(checked: boolean | "indeterminate") {
  if (checked === "indeterminate") return
  if (checked) fileList.selectAll()
  else fileList.clearSelection()
}
</script>

<template>
  <div class="flex items-center h-9 px-3 border-t bg-muted/30 gap-2 shrink-0">
    <Checkbox
      :model-value="fileList.selectedCount === fileList.allEntries.length"
      class="size-3.5"
      @update:model-value="handleSelectAll"
    />
    <span class="text-xs text-muted-foreground">
      {{ fileList.selectedCount }} selected
    </span>

    <div class="flex-1" />

    <template v-if="!ui.readonly">
      <Button variant="ghost" size="xs" @click="copySelected">
        <Copy class="size-3.5" />
        Copy
      </Button>
      <Button variant="ghost" size="xs" @click="cutSelected">
        <Scissors class="size-3.5" />
        Cut
      </Button>
      <Button variant="ghost" size="xs" class="text-destructive hover:text-destructive" @click="deleteSelected">
        <Trash2 class="size-3.5" />
        Delete
      </Button>

      <DropdownMenu>
        <DropdownMenuTrigger as-child>
          <Button variant="ghost" size="xs">
            <FileArchive class="size-3.5" />
            Archive
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem @select="createArchive('zip')">Create .zip</DropdownMenuItem>
          <DropdownMenuItem @select="createArchive('tar')">Create .tar</DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </template>

    <Button variant="ghost" size="xs" @click="fileList.clearSelection()">
      <X class="size-3.5" />
    </Button>
  </div>
</template>
