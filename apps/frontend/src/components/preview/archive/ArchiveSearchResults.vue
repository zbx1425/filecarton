<script setup lang="ts">
import { EllipsisVertical, Search } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useUiStore } from '@/stores/ui'
import ArchiveEntryMenuBody from './ArchiveEntryMenuBody.vue'
import { getFileIcon, getFileIconColor } from '@/composables/useFileType'
import { formatSize } from '@/utils/format'
import { parentPath, splitPath } from '@/utils/path'
import type { ArchiveTreeItem } from '@/composables/useArchiveExplorer'

defineProps<{
  currentPathStr: string
  searchQuery: string
  results: ArchiveTreeItem[]
  randomAccess: boolean
}>()

const ui = useUiStore()

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

    <div v-else class="flex-1 overflow-auto">
      <ContextMenu v-for="item in results" :key="item.path">
        <ContextMenuTrigger as-child>
          <div
            v-if="ui.isMobile"
            class="flex min-h-14 items-center ps-4 pe-3 text-sm touch-manipulation"
            @click="emit('openNode', item)"
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
            <DropdownMenu>
              <DropdownMenuTrigger as-child>
                <Button variant="ghost" size="icon-sm" class="shrink-0" :aria-label="$t('common.a11y.more')" @click.stop>
                  <EllipsisVertical class="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" class="w-48">
                <ArchiveEntryMenuBody
                  surface="dropdown"
                  :show-shortcuts="false"
                  :flatten-subs="false"
                  :is-dir="item.isDir"
                  :random-access="randomAccess"
                  @open="emit('openNode', item)"
                  @download="emit('downloadNode', item)"
                />
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
          <div
            v-else
            class="flex items-center px-3 text-sm cursor-pointer hover:bg-muted/50 gap-2 h-(--fc-row-height)"
            @click="emit('openNode', item)"
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
        </ContextMenuTrigger>
        <ContextMenuContent class="w-48">
          <ArchiveEntryMenuBody
            surface="context"
            :show-shortcuts="false"
            :flatten-subs="false"
            :is-dir="item.isDir"
            :random-access="randomAccess"
            @open="emit('openNode', item)"
            @download="emit('downloadNode', item)"
          />
        </ContextMenuContent>
      </ContextMenu>
    </div>
  </div>
</template>
