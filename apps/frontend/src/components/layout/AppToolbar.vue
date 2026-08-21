<script setup lang="ts">
import { computed, ref } from 'vue'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import { useUploadStore } from '@/stores/upload'
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
  Home,
  PanelLeft,
  Plus,
  Upload,
  Search,
  File,
  Folder,
  ChevronDown,
} from '@lucide/vue'
import { createItem } from '@/composables/useFileActions'
import { isInternalDrag, handleDrop } from '@/composables/useDragDrop'
import ClipboardBadge from '@/components/ClipboardBadge.vue'

const navigation = useNavigationStore()
const ui = useUiStore()
const upload = useUploadStore()

const pathSegments = computed(() => navigation.currentPath)
const isAtRoot = computed(() => pathSegments.value.length === 0 && !navigation.activeFile)

function navigateToSegment(index: number) {
  navigation.navigateTo(pathSegments.value.slice(0, index + 1))
}

const dropHoverSegment = ref<number | null>(null)

function segmentPath(index: number): string {
  return pathSegments.value.slice(0, index + 1).join('/')
}

function handleBreadcrumbDragOver(e: DragEvent, index: number) {
  if (!isInternalDrag(e)) return
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'
  dropHoverSegment.value = index
}

function handleBreadcrumbDragLeave() {
  dropHoverSegment.value = null
}

async function handleBreadcrumbDrop(e: DragEvent, index: number) {
  dropHoverSegment.value = null
  const targetPath = index === -1 ? '' : segmentPath(index)
  await handleDrop(e, targetPath)
}
</script>

<template>
  <div class="flex items-center h-11 px-3 border-b gap-2 shrink-0 bg-background">
    <Button
      v-if="ui.showTreeToggle"
      variant="ghost"
      size="icon-sm"
      @click="ui.toggleTreePanel()"
    >
      <PanelLeft class="size-4" />
    </Button>

    <Breadcrumb class="flex-1 min-w-0">
      <BreadcrumbList>
        <BreadcrumbItem>
          <BreadcrumbPage v-if="isAtRoot" class="flex items-center gap-1.5">
            <Home class="size-4" />
            <span>{{ ui.repoName }}</span>
          </BreadcrumbPage>
          <BreadcrumbLink
            v-else
            class="flex items-center gap-1.5 cursor-pointer"
            :class="dropHoverSegment === -1 ? 'bg-primary/10 rounded px-1' : ''"
            @click="navigation.navigateTo([])"
            @dragover="handleBreadcrumbDragOver($event, -1)"
            @dragleave="handleBreadcrumbDragLeave"
            @drop="handleBreadcrumbDrop($event, -1)"
          >
            <Home class="size-4" />
          </BreadcrumbLink>
        </BreadcrumbItem>

        <template v-for="(segment, i) in pathSegments" :key="i">
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbLink
              v-if="i < pathSegments.length - 1 || navigation.activeFile"
              class="cursor-pointer"
              :class="dropHoverSegment === i ? 'bg-primary/10 rounded px-1' : ''"
              @click="navigateToSegment(i)"
              @dragover="handleBreadcrumbDragOver($event, i)"
              @dragleave="handleBreadcrumbDragLeave"
              @drop="handleBreadcrumbDrop($event, i)"
            >
              {{ segment }}
            </BreadcrumbLink>
            <BreadcrumbPage v-else>{{ segment }}</BreadcrumbPage>
          </BreadcrumbItem>
        </template>

        <template v-if="navigation.activeFile">
          <BreadcrumbSeparator />
          <BreadcrumbItem>
            <BreadcrumbPage class="font-mono text-xs">
              {{ navigation.activeFile }}
            </BreadcrumbPage>
          </BreadcrumbItem>
        </template>
      </BreadcrumbList>
    </Breadcrumb>

    <div class="flex items-center gap-1">
      <div class="flex items-center">
        <div class="relative">
          <Search class="absolute left-2 top-1/2 -translate-y-1/2 size-3.5 text-muted-foreground pointer-events-none" />
          <Input
            v-model="ui.searchQuery"
            placeholder="Search..."
            class="h-7 w-40 pl-7 pr-7 text-xs"
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
              :model-value="!ui.searchRecursive"
              @update:model-value="ui.searchRecursive = false"
            >
              Current directory
            </DropdownMenuCheckboxItem>
            <DropdownMenuCheckboxItem
              :model-value="ui.searchRecursive"
              @update:model-value="ui.searchRecursive = true"
            >
              All subfolders
            </DropdownMenuCheckboxItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      <ClipboardBadge />

      <template v-if="!ui.readonly">
        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <Button variant="outline" size="sm">
              <Plus class="size-4" />
              New
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem @select="createItem('file')">
              <File class="size-4" />
              New File
            </DropdownMenuItem>
            <DropdownMenuItem @select="createItem('dir')">
              <Folder class="size-4" />
              New Folder
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>

        <Button variant="outline" size="sm" @click="upload.show()">
          <Upload class="size-4" />
          Upload
        </Button>
      </template>
    </div>
  </div>
</template>
