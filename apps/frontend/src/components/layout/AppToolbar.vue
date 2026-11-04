<script setup lang="ts">
import { computed, ref, watch, nextTick } from 'vue'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import { useUploadStore } from '@/stores/upload'
import { useAuthStore } from '@/stores/auth'
import { usePreferencesStore } from '@/stores/preferences'
import { apiPost } from '@/api/client'
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Separator } from '@/components/ui/separator'
import {
  Home,
  PanelLeft,
  Plus,
  Upload,
  File,
  Folder,
  CloudDownload,
  Settings2,
  LogOut,
} from '@lucide/vue'
import SearchBox from '@/components/SearchBox.vue'
import { createItem } from '@/composables/useFileActions'
import { showRemoteDownloadDialog } from '@/composables/useDialogs'
import { isInternalDrag, handleDrop } from '@/composables/useDragDrop'
import ClipboardBadge from '@/components/ClipboardBadge.vue'

const navigation = useNavigationStore()
const ui = useUiStore()
const upload = useUploadStore()
const auth = useAuthStore()
const prefs = usePreferencesStore()

async function handleLogout() {
  if (navigation.editDirty) {
    const leave = await navigation.checkDirty()
    if (!leave) return
  }
  navigation.editDirty = false
  if (upload.hasActive) upload.cancelAll()
  try {
    await apiPost('auth_logout', {})
  } catch { /* session already invalid */ }
  location.reload()
}

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

const breadcrumbNav = ref<HTMLElement | null>(null)

watch(
  () => [navigation.currentPath, navigation.activeFile],
  () => nextTick(() => {
    const el = breadcrumbNav.value
    if (el) el.scrollLeft = el.scrollWidth
  }),
  { immediate: true },
)
</script>

<template>
  <div
    data-slot="app-toolbar"
    :data-toolbar-custom="prefs.isToolbarCustom ? 'true' : undefined"
    class="flex items-center h-11 px-3 border-b gap-2 shrink-0 bg-background text-foreground transition-colors"
    :class="prefs.isToolbarCustom ? (prefs.toolbarTone === 'dark' ? 'dark' : 'light') : ''"
  >
    <Button
      v-if="ui.showTreeToggle"
      variant="ghost"
      size="icon-sm"
      aria-label="Toggle tree panel"
      @click="ui.toggleTreePanel()"
    >
      <PanelLeft class="size-4" />
    </Button>

    <span v-if="ui.branding" class="text-base font-semibold tracking-tight shrink-0 mr-1">
      {{ ui.branding }}
    </span>

    <Breadcrumb class="flex-1 min-w-0 overflow-x-auto scrollbar-none" :ref="(el: any) => breadcrumbNav = el?.$el ?? el">
      <BreadcrumbList class="flex-nowrap w-max">
        <BreadcrumbItem class="shrink-0">
          <BreadcrumbPage v-if="isAtRoot" class="flex items-center gap-1.5">
            <Home class="size-4" />
            <span v-if="ui.repoName">{{ ui.repoName }}</span>
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
          <BreadcrumbSeparator class="shrink-0" />
          <BreadcrumbItem class="shrink-0">
            <BreadcrumbLink
              v-if="i < pathSegments.length - 1 || navigation.activeFile"
              class="cursor-pointer max-w-40 truncate block"
              :class="dropHoverSegment === i ? 'bg-primary/10 rounded px-1' : ''"
              :title="segment"
              @click="navigateToSegment(i)"
              @dragover="handleBreadcrumbDragOver($event, i)"
              @dragleave="handleBreadcrumbDragLeave"
              @drop="handleBreadcrumbDrop($event, i)"
            >
              {{ segment }}
            </BreadcrumbLink>
            <BreadcrumbPage v-else class="max-w-40 truncate block" :title="segment">{{ segment }}</BreadcrumbPage>
          </BreadcrumbItem>
        </template>

        <template v-if="navigation.activeFileName">
          <BreadcrumbSeparator class="shrink-0" />
          <BreadcrumbItem class="shrink-0">
            <BreadcrumbPage class="font-mono text-sm max-w-48 truncate block" :title="navigation.activeFileName">
              {{ navigation.activeFileName }}
            </BreadcrumbPage>
          </BreadcrumbItem>
        </template>
      </BreadcrumbList>
    </Breadcrumb>

    <div class="flex items-center gap-2">
      <template v-if="navigation.viewMode === 'list'">
        <SearchBox
          v-model="navigation.searchQuery"
          placeholder="Search..."
          input-class="w-36 sm:w-60 max-w-full"
          :input-attrs="{ 'data-search': '' }"
          class="me-1"
        >
          <template #panel>
            <div class="flex justify-end gap-0">
              <Button :variant="!navigation.searchRecursive ? 'default' : 'ghost'" size="sm" @click="navigation.searchRecursive = false">
                Here
              </Button>
              <Button :variant="navigation.searchRecursive ? 'default' : 'ghost'" size="sm" @click="navigation.searchRecursive = true">
                & Subfolders
              </Button>
            </div>
          </template>
        </SearchBox>

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
              <template v-if="ui.features.remoteDownload">
                <DropdownMenuSeparator />
                <DropdownMenuItem @select="showRemoteDownloadDialog()">
                  <CloudDownload class="size-4" />
                  Remote Download
                </DropdownMenuItem>
              </template>
            </DropdownMenuContent>
          </DropdownMenu>

          <Button variant="outline" size="sm" @click="upload.openUploadDialog()">
            <Upload class="size-4" />
            Upload
          </Button>
        </template>
      </template>

      <template v-if="auth.showLogout">
        <Separator
          orientation="vertical"
          class="data-[orientation=vertical]:h-6"
        />
        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <Button
              variant="ghost"
              size="sm"
              class="max-w-30 overflow-hidden px-2 -ms-2 font-normal text-sm text-muted-foreground"
            >
              <span class="min-w-0 truncate">{{ auth.user?.displayName }}</span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" class="w-52">
            <DropdownMenuLabel class="font-normal">
              <div class="flex flex-col gap-0.5">
                <span class="truncate text-sm leading-snug">{{ auth.user?.displayName }}</span>
                <span class="truncate text-sm leading-snug text-muted-foreground" 
                  v-if="auth.user?.id != auth.user?.displayName">{{ auth.user?.id }}</span>
              </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem @select="handleLogout">
              <LogOut class="size-4" />
              Log out
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </template>

      <Button variant="ghost" size="icon-sm" class="-ms-2" aria-label="Settings" @click="ui.settingsOpen = true">
        <Settings2 class="size-4" />
      </Button>
    </div>
  </div>
</template>
