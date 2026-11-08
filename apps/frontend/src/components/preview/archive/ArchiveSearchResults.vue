<script setup lang="ts">
import { ref, watch } from 'vue'
import { EllipsisVertical, Search } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import { useUiStore } from '@/stores/ui'
import { AnchoredMenu, type AnchoredMenuHandle } from '@/components/ui/anchored-menu'
import ArchiveEntryMenuBody from './ArchiveEntryMenuBody.vue'
import { getFileIcon, getFileIconColor } from '@/composables/useFileType'
import { formatSize } from '@/utils/format'
import { parentPath, splitPath } from '@/utils/path'
import type { ArchiveTreeItem } from '@/composables/useArchiveExplorer'

const props = defineProps<{
  currentPathStr: string
  searchQuery: string
  results: ArchiveTreeItem[]
  randomAccess: boolean
}>()

const ui = useUiStore()
const menuItem = ref<ArchiveTreeItem | null>(null)
const overflowItem = ref<ArchiveTreeItem | null>(null)
const rowMenu = ref<AnchoredMenuHandle | null>(null)

watch(() => props.results.map(item => item.path), (paths) => {
  const current = overflowItem.value
  if (current && rowMenu.value?.isExpanded(current.path) && !paths.includes(current.path)) {
    rowMenu.value.release(current.path)
  }
})

function onListContextMenu(e: MouseEvent) {
  if (!(e.target as HTMLElement).closest('[data-archive-file]')) e.preventDefault()
}

const emit = defineEmits<{
  openNode: [node: ArchiveTreeItem]
  navigateDir: [segments: string[]]
  downloadNode: [node: ArchiveTreeItem]
}>()

function handleDirClick(pathStr: string) {
  const parent = parentPath(pathStr)
  emit('navigateDir', parent ? splitPath(parent) : [])
}
</script>

<template>
  <div class="flex flex-col h-full">
    <div class="flex items-center px-3 h-8 text-sm text-muted-foreground border-b bg-muted/30 shrink-0">
      <Search class="size-3.5 mr-2" />
      {{ currentPathStr
        ? $t('archive.search.banner', { path: currentPathStr, query: searchQuery })
        : $t('archive.search.banner_root', { query: searchQuery }) }}
    </div>

    <div v-if="results.length === 0" class="flex-1 flex items-center justify-center text-sm text-muted-foreground">
      {{ $t('files.search.no_results') }}
    </div>

    <ContextMenu v-else>
    <ContextMenuTrigger as-child>
    <div class="flex-1 overflow-auto" @contextmenu="onListContextMenu">
      <template v-for="item in results" :key="item.path">
          <div
            v-if="ui.isMobile"
            data-archive-file
            class="flex min-h-14 items-center ps-4 pe-3 text-sm touch-manipulation"
            @click="emit('openNode', item)"
            @contextmenu="menuItem = item"
          >
            <div class="flex min-w-0 flex-1 flex-col justify-center gap-0.5 py-1.5 me-2">
              <span class="truncate font-medium">{{ item.name }}</span>
              <span class="flex min-w-0 items-center text-xs text-muted-foreground">
                <span
                  class="min-w-0 flex-1 truncate"
                  @click.stop="handleDirClick(item.path)"
                >
                  /{{ parentPath(item.path) || $t('common.path.root') }}
                </span>
                <span v-if="!item.isDir" class="w-14 shrink-0 text-right tabular-nums">{{ formatSize(item.size) }}</span>
              </span>
            </div>
            <Button
              variant="ghost"
              size="icon-sm"
              class="pointer-events-auto shrink-0"
              :aria-label="$t('common.a11y.more')"
              aria-haspopup="menu"
              :aria-expanded="rowMenu?.isExpanded(item.path) ?? false"
              @pointerdown="rowMenu?.onPointerDown(item.path, $event)"
              @click.stop="(e: MouseEvent) => rowMenu?.onClick(item.path, e, () => { overflowItem = item })"
            >
              <EllipsisVertical class="size-4" />
            </Button>
          </div>
          <div
            v-else
            data-archive-file
            class="flex items-center px-3 text-sm cursor-pointer hover:bg-muted/50 gap-2 h-(--fc-row-height)"
            @click="emit('openNode', item)"
            @contextmenu="menuItem = item"
          >
            <component
              :is="getFileIcon(item.name, item.isDir)"
              class="size-4 shrink-0"
              :class="getFileIconColor(item.name, item.isDir)"
            />
            <span class="truncate font-medium">{{ item.name }}</span>
            <span
              class="text-muted-foreground truncate cursor-pointer hover:underline"
              @click.stop="handleDirClick(item.path)"
            >
              /{{ parentPath(item.path) || $t('common.path.root') }}
            </span>
            <span v-if="!item.isDir" class="text-muted-foreground shrink-0 tabular-nums ml-auto">
              {{ formatSize(item.size) }}
            </span>
          </div>
      </template>
    </div>
    </ContextMenuTrigger>
    <ContextMenuContent v-if="menuItem" class="w-48">
      <ArchiveEntryMenuBody
        surface="context"
        :show-shortcuts="false"
        :flatten-subs="false"
        :is-dir="menuItem.isDir"
        :random-access="randomAccess"
        @open="emit('openNode', menuItem)"
        @download="emit('downloadNode', menuItem)"
      />
    </ContextMenuContent>
    </ContextMenu>
    <AnchoredMenu v-if="ui.isMobile" ref="rowMenu" content-class="w-48">
      <ArchiveEntryMenuBody
        v-if="overflowItem"
        surface="dropdown"
        :show-shortcuts="false"
        :flatten-subs="false"
        :is-dir="overflowItem.isDir"
        :random-access="randomAccess"
        @open="emit('openNode', overflowItem)"
        @download="emit('downloadNode', overflowItem)"
      />
    </AnchoredMenu>
  </div>
</template>
