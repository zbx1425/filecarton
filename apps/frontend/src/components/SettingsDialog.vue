<script setup lang="ts">
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Slider } from '@/components/ui/slider'
import { Separator } from '@/components/ui/separator'
import { useUiStore } from '@/stores/ui'
import { usePreferencesStore } from '@/stores/preferences'
import { Sun, Moon } from '@lucide/vue'
import Badge from './ui/badge/Badge.vue'

const appVersion = __APP_VERSION__
const ui = useUiStore()
const prefs = usePreferencesStore()

function setFontSize(val: number[] | undefined) {
  if (val === undefined) return
  prefs.baseFontSize = val[0]
}

function setEditorFontSize(val: number[] | undefined) {
  if (val === undefined) return
  prefs.editorFontSize = val[0]
}
</script>

<template>
  <Dialog v-model:open="ui.settingsOpen">
    <DialogContent class="sm:max-w-lg max-h-[85vh] overflow-y-auto pb-8">
      <DialogHeader>
        <DialogTitle>Settings</DialogTitle>
      </DialogHeader>

      <div class="space-y-5 pt-2">
        <!-- About -->
        <div class="flex items-center gap-3 px-4 py-3 border rounded-md bg-muted/20">
          <div class="flex-1">
            <div class="font-semibold text-sm">
              FileCarton
              <span class="font-medium">by Zbx1425</span>
            </div>
            <div class="text-xs text-muted-foreground">
              A modern self-hosted file manager,<br>mostly written with LLM
            </div>
          </div>
          <div class="float-end">
            <Badge variant="secondary">v{{ appVersion }}</Badge>
          </div>
        </div>

        <Separator />

        <!-- Appearance -->
        <div class="space-y-4">
          <h3 class="text-sm font-medium">Appearance</h3>

          <div class="grid grid-cols-2 gap-x-6 gap-y-4">
            <!-- Theme -->
            <div class="space-y-1.5">
              <label class="text-xs text-muted-foreground">Theme</label>
              <div class="flex gap-1">
                <Button
                  size="sm"
                  :variant="prefs.theme === 'light' ? 'default' : 'outline'"
                  class="flex-1"
                  @click="prefs.theme = 'light'"
                >
                  <Sun class="size-3.5" />
                  Light
                </Button>
                <Button
                  size="sm"
                  :variant="prefs.theme === 'dark' ? 'default' : 'outline'"
                  class="flex-1"
                  @click="prefs.theme = 'dark'"
                >
                  <Moon class="size-3.5" />
                  Dark
                </Button>
              </div>
            </div>

            <!-- Density -->
            <div class="space-y-1.5">
              <label class="text-xs text-muted-foreground">Display Density</label>
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
            </div>

            <!-- Base Font Size -->
            <div class="col-span-2 space-y-1.5 mb-3">
              <div class="flex items-center justify-between">
                <label class="text-xs text-muted-foreground">Base Font Size</label>
                <span class="text-xs tabular-nums text-muted-foreground">{{ prefs.baseFontSize }}px</span>
              </div>
              <Slider
                :model-value="[prefs.baseFontSize]"
                :min="12"
                :max="20"
                :step="1"
                @update:model-value="setFontSize"
              />
            </div>
          </div>
        </div>

        <Separator />

        <!-- Editor -->
        <div class="space-y-4">
          <h3 class="text-sm font-medium">Editor</h3>

          <div class="space-y-1.5">
            <div class="flex items-center justify-between">
              <label class="text-xs text-muted-foreground">Editor Font Size</label>
              <span class="text-xs tabular-nums text-muted-foreground">{{ prefs.editorFontSize }}px</span>
            </div>
            <Slider
              :model-value="[prefs.editorFontSize]"
              :min="10"
              :max="24"
              :step="1"
              @update:model-value="setEditorFontSize"
            />
          </div>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>
