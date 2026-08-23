<script setup lang="ts">
import {
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuShortcut,
} from '@/components/ui/context-menu'
import {
  FolderOpen, Eye, Copy, Scissors, ClipboardPaste,
  Pencil, Trash2, Download,
} from '@lucide/vue'

defineProps<{
  isDir: boolean
  readonly: boolean
  canPaste: boolean
  showOpen?: boolean
  showRename?: boolean
  showDownload?: boolean
  hasHotkey?: boolean
}>()

defineEmits<{
  open: []
  copy: []
  cut: []
  paste: []
  rename: []
  delete: []
  download: []
}>()
</script>

<template>
  <template v-if="showOpen !== false">
    <ContextMenuItem @select="$emit('open')">
      <FolderOpen v-if="isDir" class="size-4" />
      <Eye v-else class="size-4" />
      Open
    </ContextMenuItem>
    <ContextMenuSeparator v-if="!readonly" />
  </template>

  <template v-if="!readonly">
    <ContextMenuItem @select="$emit('copy')">
      <Copy class="size-4" />
      Copy
      <ContextMenuShortcut v-if="hasHotkey">Ctrl+C</ContextMenuShortcut>
    </ContextMenuItem>
    <ContextMenuItem @select="$emit('cut')">
      <Scissors class="size-4" />
      Cut
      <ContextMenuShortcut v-if="hasHotkey">Ctrl+X</ContextMenuShortcut>
    </ContextMenuItem>
    <ContextMenuItem v-if="canPaste" @select="$emit('paste')">
      <ClipboardPaste class="size-4" />
      Paste
      <ContextMenuShortcut v-if="hasHotkey">Ctrl+V</ContextMenuShortcut>
    </ContextMenuItem>
    <ContextMenuSeparator />
    <ContextMenuItem v-if="showRename !== false" @select="$emit('rename')">
      <Pencil class="size-4" />
      Rename
      <ContextMenuShortcut v-if="hasHotkey">F2</ContextMenuShortcut>
    </ContextMenuItem>
    <ContextMenuItem class="text-destructive focus:text-destructive" @select="$emit('delete')">
      <Trash2 class="size-4" />
      Delete
      <ContextMenuShortcut v-if="hasHotkey">Del</ContextMenuShortcut>
    </ContextMenuItem>
  </template>

  <template v-if="showDownload !== false">
    <ContextMenuSeparator />
    <ContextMenuItem @select="$emit('download')">
      <Download class="size-4" />
      Download
    </ContextMenuItem>
  </template>

  <slot />
</template>
