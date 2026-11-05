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
  downloadLabel?: string
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
      {{ $t('common.action.open') }}
    </ContextMenuItem>
    <ContextMenuSeparator v-if="!readonly" />
  </template>

  <template v-if="!readonly">
    <ContextMenuItem @select="$emit('copy')">
      <Copy class="size-4" />
      {{ $t('common.action.copy') }}
      <ContextMenuShortcut v-if="hasHotkey">{{ $t('common.shortcut.copy') }}</ContextMenuShortcut>
    </ContextMenuItem>
    <ContextMenuItem @select="$emit('cut')">
      <Scissors class="size-4" />
      {{ $t('common.action.cut') }}
      <ContextMenuShortcut v-if="hasHotkey">{{ $t('common.shortcut.cut') }}</ContextMenuShortcut>
    </ContextMenuItem>
    <ContextMenuItem v-if="canPaste" @select="$emit('paste')">
      <ClipboardPaste class="size-4" />
      {{ $t('common.action.paste') }}
      <ContextMenuShortcut v-if="hasHotkey">{{ $t('common.shortcut.paste') }}</ContextMenuShortcut>
    </ContextMenuItem>
    <ContextMenuSeparator />
    <ContextMenuItem v-if="showRename !== false" @select="$emit('rename')">
      <Pencil class="size-4" />
      {{ $t('common.action.rename') }}
      <ContextMenuShortcut v-if="hasHotkey">{{ $t('common.shortcut.rename') }}</ContextMenuShortcut>
    </ContextMenuItem>
    <ContextMenuItem class="text-destructive focus:text-destructive" @select="$emit('delete')">
      <Trash2 class="size-4" />
      {{ $t('common.action.delete') }}
      <ContextMenuShortcut v-if="hasHotkey">{{ $t('common.shortcut.delete') }}</ContextMenuShortcut>
    </ContextMenuItem>
  </template>

  <template v-if="showDownload !== false">
    <ContextMenuSeparator />
    <ContextMenuItem @select="$emit('download')">
      <Download class="size-4" />
      {{ downloadLabel ?? $t('common.action.download') }}
    </ContextMenuItem>
  </template>

  <slot />
</template>
