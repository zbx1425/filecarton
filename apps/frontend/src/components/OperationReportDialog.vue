<script setup lang="ts">
import { computed } from 'vue'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Button } from '@/components/ui/button'
import { dialogState, reasonLabel } from '@/composables/useDialogs'
import { AlertTriangle } from '@lucide/vue'

const state = dialogState.operationReport

interface Group {
  reason: string
  label: string
  items: string[]
}

const groups = computed<Group[]>(() => {
  const map = new Map<string, string[]>()
  for (const item of state.items) {
    let arr = map.get(item.reason)
    if (!arr) { arr = []; map.set(item.reason, arr) }
    arr.push(item.name)
  }
  return Array.from(map.entries()).map(([reason, items]) => ({
    reason,
    label: reasonLabel(reason),
    items,
  }))
})

const isSingleGroup = computed(() => groups.value.length === 1)

const defaultOpenValues = computed(() => groups.value.map(g => g.reason))

function resolve(value: boolean) {
  state.resolve?.(value)
  state.open = false
  state.resolve = null
}

function handleOpenChange(open: boolean) {
  if (!open) resolve(false)
}
</script>

<template>
  <Dialog :open="state.open" @update:open="handleOpenChange">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ state.title }}</DialogTitle>
        <DialogDescription v-if="state.description" class="whitespace-pre-line">
          {{ state.description }}
        </DialogDescription>
      </DialogHeader>

      <!-- Warning banner -->
      <div
        v-if="state.warningBanner"
        class="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive"
      >
        <AlertTriangle class="size-3.5 shrink-0 mt-0.5" />
        <span>{{ state.warningBanner }}</span>
      </div>

      <!-- File list -->
      <ScrollArea class="max-h-[50vh]">
        <!-- Single group: flat list without accordion -->
        <div v-if="isSingleGroup" class="border rounded-md p-2">
          <div class="text-xs font-medium text-muted-foreground mb-1.5">
            {{ groups[0].label }} ({{ groups[0].items.length }})
          </div>
          <div class="space-y-0.5 text-xs font-mono break-all">
            <div
              v-for="name in groups[0].items"
              :key="name"
              :class="name === state.highlightItem ? 'text-destructive font-medium' : ''"
            >
              {{ name }}
              <span
                v-if="name === state.highlightItem"
                class="text-destructive opacity-70 font-sans"
              >(editing)</span>
            </div>
          </div>
        </div>

        <!-- Multiple groups: accordion -->
        <Accordion
          v-else
          type="multiple"
          :default-value="defaultOpenValues"
          class="border rounded-md px-3"
        >
          <AccordionItem
            v-for="group in groups"
            :key="group.reason"
            :value="group.reason"
          >
            <AccordionTrigger class="text-xs py-2.5">
              <span>
                {{ group.label }}
                <span class="ml-1 text-muted-foreground">({{ group.items.length }})</span>
              </span>
            </AccordionTrigger>
            <AccordionContent class="pb-2">
              <div class="space-y-0.5 text-xs font-mono break-all">
                <div
                  v-for="name in group.items"
                  :key="name"
                  :class="name === state.highlightItem ? 'text-destructive font-medium' : ''"
                >
                  {{ name }}
                  <span
                    v-if="name === state.highlightItem"
                    class="text-destructive opacity-70 font-sans"
                  >(editing)</span>
                </div>
              </div>
            </AccordionContent>
          </AccordionItem>
        </Accordion>
      </ScrollArea>

      <DialogFooter>
        <template v-if="state.continueLabel">
          <Button variant="outline" @click="resolve(false)">Cancel</Button>
          <Button
            :variant="state.dangerContinue ? 'destructive' : 'default'"
            @click="resolve(true)"
          >
            {{ state.continueLabel }}
          </Button>
        </template>
        <Button v-else @click="resolve(false)">OK</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
