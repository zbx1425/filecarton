<script setup lang="ts">
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useUiStore } from '@/stores/ui'
import { usePreferencesStore } from '@/stores/preferences'
import { FILE_LIST_VIEW_KEY } from './fileListContext'
import {
  copySelected, cutSelected, deleteSelected,
  openPermissionsSelected, canModifySelectedPermissions,
} from '@/services/fileActions'
import { createArchive } from '@/composables/useArchive'
import { fetchBatchDownload, buildDownloadUrl } from '@/api/client'
import { joinPath } from '@/utils/path'
import { errorMessage } from '@/utils/errorMessage'
import { toast } from 'vue-sonner'
import { Copy, Scissors, Trash2, X, FileArchive, Shield, Download, EllipsisVertical } from '@lucide/vue'

const view = inject(FILE_LIST_VIEW_KEY) ?? missingFileListView()
const ui = useUiStore()
const prefs = usePreferencesStore()

function missingFileListView(): never {
  throw new Error('BatchActionBar requires FILE_LIST_VIEW_KEY')
}
const downloading = ref(false)

/** Strip directory prefix from absolute-path selection set to get bare filenames */
function selectedNames(): string[] {
  const prefix = view.currentPathStr ? view.currentPathStr + '/' : ''
  const names: string[] = []
  for (const p of view.selected) {
    const name = prefix && p.startsWith(prefix) ? p.slice(prefix.length) : p
    if (!name.includes('/')) names.push(name)
  }
  return names
}

const isSingleFile = computed(() => {
  const names = selectedNames()
  if (names.length !== 1) return false
  return !!view.getFileByName(names[0])
})

async function downloadSelected() {
  const names = selectedNames()
  if (names.length === 0) return
  if (isSingleFile.value) {
    const filePath = joinPath(view.currentPathStr, names[0])
    window.open(buildDownloadUrl(filePath), '_blank')
    return
  }
  downloading.value = true
  try {
    await fetchBatchDownload(view.currentPathStr, names)
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    downloading.value = false
  }
}

const canModifyPermissions = computed(() => canModifySelectedPermissions())

const checkboxState = computed(() => {
  if (view.isAllSelected) return true
  return 'indeterminate' as const
})

function handleSelectAll(checked: boolean | "indeterminate") {
  if (checked === true) view.selectAll()
  else view.clearSelection()
}

const barRef = ref<HTMLElement | null>(null)
const level = ref(0)
let lastWidth = 0
let generation = 0
let resizeObserver: ResizeObserver | null = null

const iconMode = computed(() => ui.isMobile || level.value >= 1)
const foldCopyCut = computed(() => level.value >= 2)
const showArchive = computed(() => !ui.readonly && ui.features.archive)
const showPermissions = computed(() => !ui.readonly && ui.features.unixPermModify)
const showMore = computed(() => showArchive.value || showPermissions.value || (foldCopyCut.value && !ui.readonly))
const layoutKey = computed(() => [
  ui.isMobile,
  ui.readonly,
  ui.features.archive,
  ui.features.unixPermModify,
  view.selectedCount,
  isSingleFile.value,
  prefs.locale,
].join('|'))

async function measure(reset: boolean) {
  const token = ++generation
  if (!barRef.value) return
  if (reset) {
    level.value = 0
    await nextTick()
  } else if (level.value > 0 && barRef.value.clientWidth >= barRef.value.scrollWidth + 24) {
    level.value -= 1
    await nextTick()
  }
  if (token !== generation || !barRef.value) return
  let guard = 0
  while (barRef.value.scrollWidth > barRef.value.clientWidth && level.value < 2 && guard < 4) {
    level.value += 1
    guard += 1
    await nextTick()
    if (token !== generation || !barRef.value) return
  }
}

onMounted(() => {
  const el = barRef.value
  if (!el) return
  lastWidth = el.clientWidth
  resizeObserver = new ResizeObserver(() => {
    const width = el.clientWidth
    if (width === lastWidth) return
    const narrower = width < lastWidth
    lastWidth = width
    void measure(narrower)
  })
  resizeObserver.observe(el)
  void measure(true)
})

onBeforeUnmount(() => {
  resizeObserver?.disconnect()
})

watch(layoutKey, () => {
  void measure(true)
})
</script>

<template>
  <div
    ref="barRef"
    class="flex min-w-0 items-center h-9 gap-2 shrink-0 overflow-hidden bg-statusbar text-statusbar-foreground border-t border-statusbar-border"
    :class="ui.isMobile ? 'ps-3 pe-0' : 'px-3'"
  >
    <Checkbox
      :model-value="checkboxState"
      class="size-3.5"
      @update:model-value="handleSelectAll"
    />
    <span class="shrink-0 text-sm text-muted-foreground">
      <template v-if="level >= 1">{{ view.selectedCount }}</template>
      <template v-else-if="ui.isMobile">{{ $t('files.selection.short', { count: view.selectedCount }) }}</template>
      <template v-else>{{ $t('files.selection.count', { count: view.selectedCount }) }}</template>
    </span>

    <div class="flex-1" />

    <template v-if="iconMode">
      <template v-if="!ui.readonly && !foldCopyCut">
        <Button variant="ghost" size="icon-sm" :aria-label="$t('common.action.copy')" @click="copySelected">
          <Copy class="size-4" />
        </Button>
        <Button variant="ghost" size="icon-sm" :aria-label="$t('common.action.cut')" @click="cutSelected">
          <Scissors class="size-4" />
        </Button>
      </template>
      <Button
        v-if="!ui.readonly"
        variant="ghost"
        size="icon-sm"
        class="text-destructive hover:text-destructive"
        :aria-label="$t('common.action.delete')"
        @click="deleteSelected"
      >
        <Trash2 class="size-4" />
      </Button>
      <Button
        v-if="isSingleFile || ui.features.archive"
        variant="ghost"
        size="icon-sm"
        :disabled="downloading"
        :aria-label="isSingleFile ? $t('common.action.download') : $t('common.action.download_all')"
        @click="downloadSelected"
      >
        <Download class="size-4" />
      </Button>
      <DropdownMenu v-if="showMore">
        <DropdownMenuTrigger as-child>
          <Button variant="ghost" size="icon-sm" :aria-label="$t('common.a11y.more')">
            <EllipsisVertical class="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <template v-if="foldCopyCut && !ui.readonly">
            <DropdownMenuItem @select="copySelected">
              <Copy class="size-4" />
              {{ $t('common.action.copy') }}
            </DropdownMenuItem>
            <DropdownMenuItem @select="cutSelected">
              <Scissors class="size-4" />
              {{ $t('common.action.cut') }}
            </DropdownMenuItem>
          </template>
          <template v-if="showArchive">
            <DropdownMenuItem @select="createArchive('zip')">{{ $t('files.action.create_archive', { format: '.zip' }) }}</DropdownMenuItem>
            <DropdownMenuItem @select="createArchive('tar')">{{ $t('files.action.create_archive', { format: '.tar' }) }}</DropdownMenuItem>
          </template>
          <DropdownMenuItem
            v-if="showPermissions"
            :disabled="!canModifyPermissions"
            @select="openPermissionsSelected"
          >
            <Shield class="size-4" />
            {{ $t('files.action.permissions') }}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </template>

    <template v-else>
      <Button v-if="isSingleFile || ui.features.archive" variant="ghost" size="sm" :disabled="downloading" @click="downloadSelected">
        <Download class="size-3.5" />
        {{ isSingleFile ? $t('common.action.download') : $t('common.action.download_all') }}
      </Button>
      <template v-if="!ui.readonly">
        <Button variant="ghost" size="sm" @click="copySelected">
          <Copy class="size-3.5" />
          {{ $t('common.action.copy') }}
        </Button>
        <Button variant="ghost" size="sm" @click="cutSelected">
          <Scissors class="size-3.5" />
          {{ $t('common.action.cut') }}
        </Button>
        <Button variant="ghost" size="sm" class="text-destructive hover:text-destructive" @click="deleteSelected">
          <Trash2 class="size-3.5" />
          {{ $t('common.action.delete') }}
        </Button>
        <DropdownMenu v-if="ui.features.archive">
          <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="sm">
              <FileArchive class="size-3.5" />
              {{ $t('common.action.archive') }}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuItem @select="createArchive('zip')">{{ $t('files.action.create_archive', { format: '.zip' }) }}</DropdownMenuItem>
            <DropdownMenuItem @select="createArchive('tar')">{{ $t('files.action.create_archive', { format: '.tar' }) }}</DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
        <Button
          v-if="ui.features.unixPermModify"
          variant="ghost"
          size="sm"
          :disabled="!canModifyPermissions"
          @click="openPermissionsSelected"
        >
          <Shield class="size-3.5" />
          {{ $t('files.action.permissions') }}
        </Button>
      </template>
    </template>

    <Button
      variant="ghost"
      :size="iconMode ? 'icon-sm' : 'sm'"
      :aria-label="$t('common.a11y.clear_selection')"
      @click="view.clearSelection()"
    >
      <X class="size-3.5" />
    </Button>
  </div>
</template>
