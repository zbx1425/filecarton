<script setup lang="ts">
import { computed, ref, watch, nextTick } from 'vue'
import { useNavigationStore } from '@/stores/navigation'
import { useUiStore } from '@/stores/ui'
import { useUploadStore } from '@/stores/upload'
import { useAuthStore } from '@/stores/auth'
import { usePreferencesStore } from '@/stores/preferences'
import { useClipboardStore } from '@/stores/clipboard'
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
  ArrowUpDown,
  CloudDownload,
  EllipsisVertical,
  File,
  Folder,
  Home,
  LogOut,
  Menu,
  PanelLeft,
  Plus,
  Search,
  Settings2,
  Upload,
} from '@lucide/vue'
import SearchBox from '@/components/SearchBox.vue'
import { createItem } from '@/services/fileActions'
import { showRemoteDownloadDialog } from '@/services/dialogs'
import { logout } from '@/services/authActions'
import { isInternalDrag, handleDrop } from '@/composables/useDragDrop'
import ClipboardBadge from '@/components/ClipboardBadge.vue'
import FileListMenuBody from '@/components/files/FileListMenuBody.vue'
import MobileSearchField from '@/components/layout/MobileSearchField.vue'
import SortDialog from '@/components/files/SortDialog.vue'

const navigation = useNavigationStore()
const ui = useUiStore()
const upload = useUploadStore()
const auth = useAuthStore()
const prefs = usePreferencesStore()
const clipboard = useClipboardStore()

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

const mobileSearchOpen = ref(false)
const sortOpen = ref(false)
const mobileSearchActive = computed(() => mobileSearchOpen.value || navigation.searchQuery !== '')
const clipboardNames = computed(() => clipboard.items.slice(0, 10))

function closeMobileSearch() {
  navigation.searchQuery = ''
  mobileSearchOpen.value = false
}

function setSortBy(column: 'name' | 'size' | 'mtime') {
  prefs.setSort(column, prefs.sortAsc)
}

function setSortAsc(asc: boolean) {
  prefs.setSort(prefs.sortBy, asc)
}
</script>

<template>
  <div
    v-if="!ui.isMobile"
    data-slot="app-toolbar"
    :data-toolbar-custom="prefs.isToolbarCustom ? 'true' : undefined"
    class="flex items-center h-11 px-3 border-b gap-2 shrink-0 bg-background text-foreground transition-colors"
    :class="prefs.isToolbarCustom ? (prefs.toolbarTone === 'dark' ? 'dark' : 'light') : ''"
  >
    <Button
      v-if="ui.showTreeToggle"
      variant="ghost"
      size="icon-sm"
      :aria-label="$t('common.a11y.toggle_tree')"
      @click="ui.toggleTreePanel()"
    >
      <PanelLeft class="size-4" />
    </Button>

    <button
      v-if="ui.branding"
      type="button"
      class="text-base font-semibold tracking-tight shrink-0 mr-1 cursor-pointer"
      @click="navigation.navigateTo([])"
    >
      {{ ui.branding }}
    </button>

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
          :placeholder="$t('files.search.placeholder')"
          input-class="w-40 lg:w-60 max-w-full"
          :input-attrs="{ 'data-search': '' }"
          class="me-1"
        >
          <template #panel>
            <div class="flex justify-end gap-0">
              <Button :variant="!navigation.searchRecursive ? 'default' : 'ghost'" size="sm" @click="navigation.searchRecursive = false">
                {{ $t('files.search.scope_here') }}
              </Button>
              <Button :variant="navigation.searchRecursive ? 'default' : 'ghost'" size="sm" @click="navigation.searchRecursive = true">
                {{ $t('files.search.scope_subfolders') }}
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
                <span class="hidden lg:inline">{{ $t('common.action.new') }}</span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem @select="createItem('file')">
                <File class="size-4" />
                {{ $t('files.action.new_file') }}
              </DropdownMenuItem>
              <DropdownMenuItem @select="createItem('dir')">
                <Folder class="size-4" />
                {{ $t('files.action.new_folder') }}
              </DropdownMenuItem>
              <template v-if="ui.features.remoteDownload">
                <DropdownMenuSeparator />
                <DropdownMenuItem @select="showRemoteDownloadDialog()">
                  <CloudDownload class="size-4" />
                  {{ $t('files.action.remote_download') }}
                </DropdownMenuItem>
              </template>
            </DropdownMenuContent>
          </DropdownMenu>

          <Button variant="outline" size="sm" @click="upload.openUploadDialog()">
            <Upload class="size-4" />
            <span class="hidden lg:inline">{{ $t('common.action.upload') }}</span>
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
              class="max-w-20 lg:max-w-30 overflow-hidden px-2 -ms-2 font-normal text-sm text-muted-foreground"
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
            <DropdownMenuItem @select="logout">
              <LogOut class="size-4" />
              {{ $t('common.action.log_out') }}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </template>

      <Button variant="ghost" size="icon-sm" class="-ms-2" :aria-label="$t('common.action.settings')" @click="ui.settingsOpen = true">
        <Settings2 class="size-4" />
      </Button>
    </div>
  </div>

  <div
    v-else
    data-slot="app-toolbar"
    :data-toolbar-custom="prefs.isToolbarCustom ? 'true' : undefined"
    class="flex flex-col shrink-0 bg-background text-foreground transition-colors"
    :class="prefs.isToolbarCustom ? (prefs.toolbarTone === 'dark' ? 'dark' : 'light') : ''"
  >
    <div class="flex items-center h-11">
      <template v-if="navigation.viewMode === 'list' && mobileSearchActive">
        <MobileSearchField
          :model-value="navigation.searchQuery"
          :recursive="navigation.searchRecursive"
          :placeholder="$t('files.search.placeholder')"
          @update:model-value="navigation.searchQuery = $event"
          @update:recursive="navigation.searchRecursive = $event"
          @back="closeMobileSearch"
        />
      </template>
      <template v-else>
        <Button
          variant="ghost"
          size="icon-sm"
          class="size-11 shrink-0 touch-manipulation"
          :aria-label="$t('common.a11y.open_menu')"
          @click="ui.mobileDrawerOpen = true"
        >
          <Menu class="size-4" />
        </Button>
        <button
          type="button"
          class="block min-w-0 max-w-full ms-2 cursor-pointer text-left"
          @click="navigation.navigateTo([])"
        >
          <div v-if="ui.branding" class="truncate text-base font-semibold leading-tight">{{ ui.branding }}</div>
          <div v-if="ui.repoName" class="truncate text-xs leading-tight text-muted-foreground">{{ ui.repoName }}</div>
        </button>
        <div class="flex-1" />
        <template v-if="navigation.viewMode === 'list'">
          <Button
            variant="ghost"
            size="icon-sm"
            class="size-11 shrink-0 touch-manipulation"
            :aria-label="$t('common.a11y.search')"
            @click="mobileSearchOpen = true"
          >
            <Search class="size-4" />
          </Button>
          <DropdownMenu>
            <DropdownMenuTrigger as-child>
              <Button
                variant="ghost"
                size="icon-sm"
                class="size-11 shrink-0 touch-manipulation"
                :aria-label="$t('common.a11y.more')"
              >
                <EllipsisVertical class="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-64">
              <template v-if="clipboard.hasContent">
                <div class="px-2 py-1.5">
                  <div class="text-sm font-medium">
                    {{ clipboard.mode === 'cut' ? $t('files.clipboard.title_cut') : $t('files.clipboard.title_copy') }}
                  </div>
                  <div class="text-sm text-muted-foreground">
                    {{ clipboard.sourcePath ? $t('files.clipboard.from', { path: clipboard.sourcePath }) : $t('files.clipboard.from_root') }}
                  </div>
                  <div class="mt-1 max-h-40 space-y-0.5 overflow-auto text-sm">
                    <div v-for="item in clipboardNames" :key="item.name" class="truncate">
                      {{ item.name }}{{ item.isDir ? '/' : '' }}
                    </div>
                    <div v-if="clipboard.items.length > clipboardNames.length" class="text-muted-foreground">
                      {{ $t('files.clipboard.more', { count: clipboard.items.length - clipboardNames.length }) }}
                    </div>
                  </div>
                  <Button variant="ghost" size="sm" class="mt-1 w-full" @click="clipboard.clear()">
                    {{ $t('common.action.clear') }}
                  </Button>
                </div>
                <DropdownMenuSeparator />
              </template>
              <FileListMenuBody
                surface="dropdown"
                :show-shortcuts="false"
                :flatten-subs="true"
                :context-target="null"
                show-upload
              />
              <DropdownMenuSeparator />
              <DropdownMenuItem @select="sortOpen = true">
                <ArrowUpDown class="size-4" />
                {{ $t('files.action.sort') }}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </template>
      </template>
    </div>

    <div class="flex h-8 items-center border-b px-3 text-xs">
      <Breadcrumb class="min-w-0 flex-1 overflow-x-auto scrollbar-none" :ref="(el: any) => breadcrumbNav = el?.$el ?? el">
        <BreadcrumbList class="flex-nowrap w-max">
          <BreadcrumbItem class="shrink-0">
            <BreadcrumbPage v-if="isAtRoot" class="flex items-center gap-1.5">
              <Home class="size-4" />
              <span v-if="ui.repoName">{{ ui.repoName }}</span>
            </BreadcrumbPage>
            <BreadcrumbLink
              v-else
              class="flex items-center gap-1.5 cursor-pointer"
              @click="navigation.navigateTo([])"
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
                :title="segment"
                @click="navigateToSegment(i)"
              >
                {{ segment }}
              </BreadcrumbLink>
              <BreadcrumbPage v-else class="max-w-40 truncate block" :title="segment">{{ segment }}</BreadcrumbPage>
            </BreadcrumbItem>
          </template>

          <template v-if="navigation.activeFileName">
            <BreadcrumbSeparator class="shrink-0" />
            <BreadcrumbItem class="shrink-0">
              <BreadcrumbPage class="font-mono max-w-48 truncate block" :title="navigation.activeFileName">
                {{ navigation.activeFileName }}
              </BreadcrumbPage>
            </BreadcrumbItem>
          </template>
        </BreadcrumbList>
      </Breadcrumb>
    </div>

    <SortDialog
      :open="sortOpen"
      :sort-by="prefs.sortBy"
      :sort-asc="prefs.sortAsc"
      @update:open="sortOpen = $event"
      @update:sort-by="setSortBy"
      @update:sort-asc="setSortAsc"
    />
  </div>
</template>
