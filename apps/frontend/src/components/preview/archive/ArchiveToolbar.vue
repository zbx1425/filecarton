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
import { Input } from '@/components/ui/input'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  DropdownMenuCheckboxItem,
} from '@/components/ui/dropdown-menu'
import {
  FileArchive,
  PanelLeft,
  Search,
  ChevronDown,
  PackageOpen,
} from '@lucide/vue'
import { useUiStore } from '@/stores/ui'
import { archiveSubdirName } from '@/utils/path'

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
  extract: [createSubdir: boolean]
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
          <BreadcrumbPage v-if="isAtRoot" class="flex items-center gap-1.5 text-xs">
            <FileArchive class="size-3.5 text-icon-archive" />
            <span class="font-medium truncate max-w-[180px]">{{ archiveName }}</span>
          </BreadcrumbPage>
          <BreadcrumbLink
            v-else
            class="flex items-center gap-1.5 cursor-pointer text-xs"
            @click="emit('navigateRoot')"
          >
            <FileArchive class="size-3.5 text-icon-archive" />
            <span class="truncate max-w-[180px]">{{ archiveName }}</span>
          </BreadcrumbLink>
        </BreadcrumbItem>

        <template v-for="(segment, i) in currentPath" :key="i">
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbLink
              v-if="i < currentPath.length - 1 || activeFileName"
              class="cursor-pointer text-xs"
              @click="emit('navigateSegment', i)"
            >
              {{ segment }}
            </BreadcrumbLink>
            <BreadcrumbPage v-else class="text-xs">{{ segment }}</BreadcrumbPage>
          </BreadcrumbItem>
        </template>

        <template v-if="activeFileName">
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbPage class="font-mono text-xs">
              {{ activeFileName }}
            </BreadcrumbPage>
          </BreadcrumbItem>
        </template>
      </BreadcrumbList>
    </Breadcrumb>

    <div class="flex items-center gap-2">
      <template v-if="viewMode === 'list'">
        <div class="flex items-center">
          <div class="relative">
            <Search class="absolute left-2 top-1/2 -translate-y-1/2 size-3.5 text-muted-foreground pointer-events-none" />
            <Input
              :model-value="searchQuery"
              placeholder="Search in archive..."
              class="h-7 w-44 pl-7 pr-7 text-xs"
              @update:model-value="emit('update:searchQuery', String($event))"
            />
          </div>
          <DropdownMenu>
            <DropdownMenuTrigger as-child>
              <Button variant="ghost" size="icon-xs" class="-ml-7 relative z-10">
                <ChevronDown class="size-3" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuCheckboxItem
                :model-value="!searchRecursive"
                @update:model-value="emit('update:searchRecursive', false)"
              >
                Current directory
              </DropdownMenuCheckboxItem>
              <DropdownMenuCheckboxItem
                :model-value="searchRecursive"
                @update:model-value="emit('update:searchRecursive', true)"
              >
                All subfolders
              </DropdownMenuCheckboxItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </template>

      <DropdownMenu v-if="!ui.readonly && ui.features.archive">
        <DropdownMenuTrigger as-child>
          <Button variant="outline" size="sm" class="h-7 text-xs">
            <PackageOpen class="size-3.5" />
            Extract
            <ChevronDown class="size-3" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
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
