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
  FileArchive,
  PanelLeft,
} from '@lucide/vue'
import SearchBox from '@/components/SearchBox.vue'
import { useUiStore } from '@/stores/ui'

defineProps<{
  archiveName: string
  currentPath: string[]
  activeFileName: string | null
  isAtRoot: boolean
  viewMode: 'list' | 'preview'
  searchQuery: string
  searchRecursive: boolean
}>()

const emit = defineEmits<{
  toggleTree: []
  navigateRoot: []
  navigateSegment: [index: number]
  'update:searchQuery': [val: string]
  'update:searchRecursive': [val: boolean]
}>()

const ui = useUiStore()
</script>

<template>
  <div class="flex items-center h-10 px-3 border-b gap-2 shrink-0 bg-background">
    <Button
      v-if="ui.isMedium"
      variant="ghost"
      size="icon-sm"
      @click="emit('toggleTree')"
    >
      <PanelLeft class="size-4" />
    </Button>

    <Breadcrumb class="flex-1 min-w-0">
      <BreadcrumbList>
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
          placeholder="Search in archive..."
          input-class="w-64 max-w-full h-7"
          :input-attrs="{ 'data-archive-search': '' }"
          @update:model-value="emit('update:searchQuery', $event)"
        >
          <template #panel>
            <div class="flex justify-end gap-1">
              <Button :variant="!searchRecursive ? 'default' : 'ghost'" size="sm" @click="emit('update:searchRecursive', false)">
                Here
              </Button>
              <Button :variant="searchRecursive ? 'default' : 'ghost'" size="sm" @click="emit('update:searchRecursive', true)">
                & Subfolders
              </Button>
            </div>
          </template>
        </SearchBox>
      </template>
    </div>
  </div>
</template>
