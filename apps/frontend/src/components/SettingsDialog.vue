<script setup lang="ts">
import { ref } from 'vue'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
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
  NumberField,
  NumberFieldContent,
  NumberFieldDecrement,
  NumberFieldIncrement,
  NumberFieldInput,
} from '@/components/ui/number-field'
import { useUiStore } from '@/stores/ui'
import { usePreferencesStore } from '@/stores/preferences'
import { Sun, Moon, Monitor, Settings, List, Code, Info } from '@lucide/vue'
import Badge from './ui/badge/Badge.vue'

const appVersion = __APP_VERSION__
const ui = useUiStore()
const prefs = usePreferencesStore()

type Section = 'general' | 'filelist' | 'editor'
const activeSection = ref<Section>('general')

const navItems: { id: Section; label: string; icon: typeof Settings }[] = [
  { id: 'general', label: 'General', icon: Settings },
  { id: 'filelist', label: 'File List', icon: List },
  { id: 'editor', label: 'Editor', icon: Code },
]

function setFontSize(val: number | undefined) {
  if (val != null) prefs.baseFontSize = val
}

function setEditorFontSize(val: number | undefined) {
  if (val != null) prefs.editorFontSize = val
}
</script>

<template>
  <Dialog v-model:open="ui.settingsOpen">
    <DialogContent class="sm:max-w-2xl max-h-[85vh] overflow-hidden p-0">
      <div class="flex flex-col sm:flex-row h-full sm:min-h-[420px]">
        <!-- Top navigation (narrow screens) -->
        <nav class="flex sm:hidden border-b bg-muted/30 px-3 pt-4 pb-0 gap-1 overflow-x-auto">
          <Button
            v-for="item in navItems"
            :key="item.id"
            variant="ghost"
            size="sm"
            class="gap-1.5 text-xs rounded-b-none shrink-0"
            :class="activeSection === item.id
              ? 'bg-background text-foreground font-medium border border-b-0 border-border'
              : 'text-muted-foreground'"
            @click="activeSection = item.id"
          >
            <component :is="item.icon" class="size-3.5" />
            {{ item.label }}
          </Button>
        </nav>

        <!-- Left navigation (wide screens) -->
        <nav class="hidden sm:flex w-44 shrink-0 border-r bg-muted/30 p-3 pt-5 flex-col gap-0.5">
          <Button
            v-for="item in navItems"
            :key="item.id"
            variant="ghost"
            size="sm"
            class="justify-start gap-2 font-normal"
            :class="activeSection === item.id
              ? 'bg-accent text-accent-foreground font-medium'
              : 'text-muted-foreground'"
            @click="activeSection = item.id"
          >
            <component :is="item.icon" class="size-3.5" />
            {{ item.label }}
          </Button>
        </nav>

        <!-- Right content -->
        <div class="flex-1 overflow-y-auto p-4 pt-4 sm:p-6 sm:pt-5">
          <DialogHeader class="mb-5">
            <DialogTitle>
              <Breadcrumb>
                <BreadcrumbList>
                  <BreadcrumbItem>
                    <BreadcrumbLink
                      v-if="activeSection !== 'general'"
                      class="cursor-pointer"
                      @click="activeSection = 'general'"
                    >
                      Settings
                    </BreadcrumbLink>
                    <BreadcrumbPage v-else>Settings</BreadcrumbPage>
                  </BreadcrumbItem>
                  <template v-if="activeSection !== 'general'">
                    <BreadcrumbSeparator />
                    <BreadcrumbItem>
                      <BreadcrumbPage>
                        {{ navItems.find(i => i.id === activeSection)?.label }}
                      </BreadcrumbPage>
                    </BreadcrumbItem>
                  </template>
                </BreadcrumbList>
              </Breadcrumb>
            </DialogTitle>
          </DialogHeader>

          <!-- General -->
          <div v-if="activeSection === 'general'" class="space-y-6">
            <!-- About card -->
            <div class="flex items-center gap-3 px-4 py-3 border rounded-md bg-muted/20">
              <div class="flex-1">
                <div class="font-semibold text-sm">
                  FileCarton
                  <span class="font-medium">by Zbx1425</span>
                </div>
                <div class="text-xs text-muted-foreground">
                  A modern, snappy, and easily embeddable web-based file manager.
                </div>
              </div>
              <Badge variant="secondary">v{{ appVersion }}</Badge>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-[140px_1fr] items-start sm:items-center gap-x-4 gap-y-3 sm:gap-y-5">
              <!-- Theme -->
              <label class="text-sm text-muted-foreground">Theme</label>
              <div class="flex gap-1">
                <Button
                  size="sm"
                  :variant="prefs.theme === 'system' ? 'default' : 'outline'"
                  class="flex-1 px-1 text-xs"
                  @click="prefs.theme = 'system'"
                >
                  <Monitor class="size-3.5" />
                  System
                </Button>
                <Button
                  size="sm"
                  :variant="prefs.theme === 'light' ? 'default' : 'outline'"
                  class="flex-1 px-1 text-xs"
                  @click="prefs.theme = 'light'"
                >
                  <Sun class="size-3.5" />
                  Light
                </Button>
                <Button
                  size="sm"
                  :variant="prefs.theme === 'dark' ? 'default' : 'outline'"
                  class="flex-1 px-1 text-xs"
                  @click="prefs.theme = 'dark'"
                >
                  <Moon class="size-3.5" />
                  Dark
                </Button>
              </div>

              <!-- Base Font Size -->
              <label class="text-sm text-muted-foreground">Font Size</label>
              <div class="flex items-center gap-2">
                <NumberField
                  :model-value="prefs.baseFontSize"
                  :min="12"
                  :max="20"
                  :step="1"
                  class="w-28"
                  @update:model-value="setFontSize"
                >
                  <NumberFieldContent>
                    <NumberFieldDecrement />
                    <NumberFieldInput />
                    <NumberFieldIncrement />
                  </NumberFieldContent>
                </NumberField>
                <span class="text-xs text-muted-foreground">px</span>
              </div>
            </div>
          </div>

          <!-- File List -->
          <div v-if="activeSection === 'filelist'" class="space-y-6">
            <div class="grid grid-cols-1 sm:grid-cols-[140px_1fr] items-start sm:items-center gap-x-4 gap-y-3 sm:gap-y-5">
              <!-- Display Density -->
              <label class="text-sm text-muted-foreground">Density</label>
              <div class="flex gap-1">
                <Button
                  size="sm"
                  :variant="prefs.displayDensity === 'compact' ? 'default' : 'outline'"
                  class="flex-1 px-1 text-xs"
                  @click="prefs.displayDensity = 'compact'"
                >
                  Compact
                </Button>
                <Button
                  size="sm"
                  :variant="prefs.displayDensity === 'default' ? 'default' : 'outline'"
                  class="flex-1 px-1 text-xs"
                  @click="prefs.displayDensity = 'default'"
                >
                  Default
                </Button>
                <Button
                  size="sm"
                  :variant="prefs.displayDensity === 'comfortable' ? 'default' : 'outline'"
                  class="flex-1 px-1 text-xs"
                  @click="prefs.displayDensity = 'comfortable'"
                >
                  Cozy
                </Button>
              </div>

              <!-- Show Hidden Files -->
              <label class="text-sm text-muted-foreground">Hidden Files</label>
              <div class="space-y-1.5">
                <div class="flex gap-1">
                  <Button
                    size="sm"
                    :variant="!prefs.showDotFiles ? 'default' : 'outline'"
                    class="flex-1 px-1 text-xs"
                    :disabled="prefs.dotFilesLocked"
                    @click="prefs.showDotFiles = false"
                  >
                    Hidden
                  </Button>
                  <Button
                    size="sm"
                    :variant="prefs.showDotFiles ? 'default' : 'outline'"
                    class="flex-1 px-1 text-xs"
                    :disabled="prefs.dotFilesLocked"
                    @click="prefs.showDotFiles = true"
                  >
                    Shown
                  </Button>
                  <div class="flex-1 px-1 text-xs" ></div>
                </div>
                <div
                  v-if="prefs.dotFilesLocked"
                  class="flex items-start gap-1.5 rounded-md border border-border/60 bg-muted/40 px-2.5 py-1.5 text-xs text-muted-foreground"
                >
                  <Info class="size-3 shrink-0 mt-0.5" />
                  <span>{{ prefs.dotFilesLockedReason }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Editor -->
          <div v-if="activeSection === 'editor'" class="space-y-6">
            <div class="grid grid-cols-1 sm:grid-cols-[140px_1fr] items-start sm:items-center gap-x-4 gap-y-3 sm:gap-y-5">
              <!-- Editor Font Size -->
              <label class="text-sm text-muted-foreground">Font Size</label>
              <div class="flex items-center gap-2">
                <NumberField
                  :model-value="prefs.editorFontSize"
                  :min="10"
                  :max="24"
                  :step="1"
                  class="w-28"
                  @update:model-value="setEditorFontSize"
                >
                  <NumberFieldContent>
                    <NumberFieldDecrement />
                    <NumberFieldInput />
                    <NumberFieldIncrement />
                  </NumberFieldContent>
                </NumberField>
                <span class="text-xs text-muted-foreground">px</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>
