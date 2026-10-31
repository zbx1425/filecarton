<script setup lang="ts">
import { ref } from 'vue'
import { Input } from '@/components/ui/input'
import { Search } from '@lucide/vue'

defineProps<{
  modelValue: string
  placeholder?: string
  inputClass?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [val: string]
}>()

const wrapperRef = ref<HTMLElement | null>(null)
const panelOpen = ref(false)

function handleFocus() {
  panelOpen.value = true
}

function handleBlur(e: FocusEvent) {
  const related = e.relatedTarget as HTMLElement | null
  if (related && wrapperRef.value?.contains(related)) return
  panelOpen.value = false
}
</script>

<template>
  <div ref="wrapperRef" class="relative" @focusout="handleBlur">
    <div class="relative">
      <Search class="absolute left-2 top-1/2 -translate-y-1/2 size-3.5 text-muted-foreground pointer-events-none" />
      <Input
        :model-value="modelValue"
        :placeholder="placeholder ?? 'Search...'"
        :class="['h-8 pl-7 text-sm', inputClass]"
        @update:model-value="emit('update:modelValue', String($event))"
        @focus="handleFocus"
      />
    </div>
    <Transition
      enter-active-class="transition-opacity duration-100"
      leave-active-class="transition-opacity duration-75"
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
    >
      <div
        v-if="panelOpen"
        class="absolute left-0 right-0 top-full mt-1 z-20 rounded-md border bg-popover p-1.5 shadow-md"
      >
        <slot name="panel" />
      </div>
    </Transition>
  </div>
</template>
