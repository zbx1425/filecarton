<script setup lang="ts">
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'
import { Button } from '@/components/ui/button'
import {
  ArrowUpDown,
  FileArchive,
  PanelLeft,
  Search,
} from '@lucide/vue'
import { ref, computed } from 'vue'
import SearchBox from '@/components/SearchBox.vue'
import MobileSearchField from '@/components/layout/MobileSearchField.vue'
import SortDialog from '@/components/files/SortDialog.vue'
import { useUiStore } from '@/stores/ui'
import type { ArchiveSortColumn } from '@/composables/useArchiveExplorer'

const props = defineProps<{
  archiveName: string
  currentPath: string[]
  activeFileName: string | null
  isAtRoot: boolean
  viewMode: 'list' | 'preview'
  searchQuery: string
  searchRecursive: boolean
  sortBy: ArchiveSortColumn
  sortAsc: boolean
}>()

const emit = defineEmits<{
  toggleTree: []
  navigateRoot: []
  navigateSegment: [index: number]
  'update:searchQuery': [val: string]
  'update:searchRecursive': [val: boolean]
  setSort: [column: ArchiveSortColumn, asc: boolean]
}>()

const ui = useUiStore()
const mobileSearchOpen = ref(false)
const sortOpen = ref(false)
const mobileSearchActive = computed(() => props.viewMode === 'list' && (mobileSearchOpen.value || props.searchQuery !== ''))

function closeMobileSearch() {
  emit('update:searchQuery', '')
  mobileSearchOpen.value = false
}
</script>

<template>
  <div
    v-if="ui.isMobile"
    class="flex items-center h-10 border-b shrink-0 bg-background"
    :class="mobileSearchActive ? '' : 'ps-3'"
  >
    <MobileSearchField
      v-if="mobileSearchActive"
      class="min-w-0 flex-1"
      :model-value="searchQuery"
      :recursive="searchRecursive"
      :placeholder="$t('archive.search.placeholder')"
      @update:model-value="emit('update:searchQuery', $event)"
      @update:recursive="emit('update:searchRecursive', $event)"
      @back="closeMobileSearch"
    />
    <template v-else>
      <Breadcrumb class="min-w-0 flex-1 overflow-x-auto scrollbar-none">
        <BreadcrumbList class="w-max flex-nowrap">
          <BreadcrumbItem>
            <BreadcrumbPage v-if="isAtRoot" class="flex items-center gap-1.5 text-sm">
              <FileArchive class="size-3.5 text-icon-archive" />
              <span class="max-w-45 truncate font-medium">{{ archiveName }}</span>
            </BreadcrumbPage>
            <BreadcrumbLink
              v-else
              class="flex cursor-pointer items-center gap-1.5 text-sm"
              @click="emit('navigateRoot')"
            >
              <FileArchive class="size-3.5 text-icon-archive" />
            </BreadcrumbLink>
          </BreadcrumbItem>
          <template v-for="(segment, i) in currentPath" :key="i">
            <BreadcrumbSeparator />
            <BreadcrumbItem>
              <BreadcrumbLink
                v-if="i < currentPath.length - 1 || activeFileName"
                class="cursor-pointer text-sm"
                @click="emit('navigateSegment', i)"
              >
                {{ segment }}
              </BreadcrumbLink>
              <BreadcrumbPage v-else class="text-sm">{{ segment }}</BreadcrumbPage>
            </BreadcrumbItem>
          </template>
          <template v-if="activeFileName">
            <BreadcrumbSeparator />
            <BreadcrumbItem>
              <BreadcrumbPage class="font-mono text-sm">{{ activeFileName }}</BreadcrumbPage>
            </BreadcrumbItem>
          </template>
        </BreadcrumbList>
      </Breadcrumb>
      <template v-if="viewMode === 'list'">
        <Button variant="ghost" size="icon-sm" class="shrink-0" :aria-label="$t('common.a11y.sort')" @click="sortOpen = true">
          <ArrowUpDown class="size-4" />
        </Button>
        <Button variant="ghost" size="icon-sm" class="shrink-0" :aria-label="$t('common.a11y.search')" @click="mobileSearchOpen = true">
          <Search class="size-4" />
        </Button>
      </template>
    </template>
    <SortDialog
      :open="sortOpen"
      :sort-by="sortBy"
      :sort-asc="sortAsc"
      @update:open="sortOpen = $event"
      @update:sort-by="emit('setSort', $event, sortAsc)"
      @update:sort-asc="emit('setSort', sortBy, $event)"
    />
  </div>
  <div v-else class="flex items-center h-10 px-3 border-b gap-2 shrink-0 bg-background">
    <Button
      v-if="!ui.isMobile"
      variant="ghost"
      size="icon-sm"
      :aria-label="$t('archive.a11y.toggle_tree')"
      @click="emit('toggleTree')"
    >
      <PanelLeft class="size-4" />
    </Button>

    <Breadcrumb class="min-w-0 flex-1 overflow-x-auto scrollbar-none">
      <BreadcrumbList class="w-max flex-nowrap">
        <BreadcrumbItem>
          <BreadcrumbPage v-if="isAtRoot" class="flex items-center gap-1.5 text-sm">
            <FileArchive class="size-3.5 text-icon-archive" />
            <span class="font-medium truncate max-w-45">{{ archiveName }}</span>
          </BreadcrumbPage>
          <BreadcrumbLink
            v-else
            class="flex items-center gap-1.5 cursor-pointer text-sm"
            @click="emit('navigateRoot')"
          >
            <FileArchive class="size-3.5 text-icon-archive" />
          </BreadcrumbLink>
        </BreadcrumbItem>

        <template v-for="(segment, i) in currentPath" :key="i">
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbLink
              v-if="i < currentPath.length - 1 || activeFileName"
              class="cursor-pointer text-sm"
              @click="emit('navigateSegment', i)"
            >
              {{ segment }}
            </BreadcrumbLink>
            <BreadcrumbPage v-else class="text-sm">{{ segment }}</BreadcrumbPage>
          </BreadcrumbItem>
        </template>

        <template v-if="activeFileName">
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbPage class="font-mono text-sm">
              {{ activeFileName }}
            </BreadcrumbPage>
          </BreadcrumbItem>
        </template>
      </BreadcrumbList>
    </Breadcrumb>

    <div class="flex items-center gap-2 min-w-0 shrink">
      <template v-if="viewMode === 'list'">
        <SearchBox
          :model-value="searchQuery"
          :placeholder="$t('archive.search.placeholder')"
          input-class="w-40 lg:w-64 max-w-full h-7"
          :input-attrs="{ 'data-archive-search': '' }"
          @update:model-value="emit('update:searchQuery', $event)"
        >
          <template #panel>
            <div class="flex justify-end gap-1">
              <Button :variant="!searchRecursive ? 'default' : 'ghost'" size="sm" @click="emit('update:searchRecursive', false)">
                {{ $t('files.search.scope_here') }}
              </Button>
              <Button :variant="searchRecursive ? 'default' : 'ghost'" size="sm" @click="emit('update:searchRecursive', true)">
                {{ $t('files.search.scope_subfolders') }}
              </Button>
            </div>
          </template>
        </SearchBox>
      </template>
    </div>
  </div>
</template>
