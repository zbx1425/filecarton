<script setup lang="ts">
import type { Component } from 'vue'
import { Button } from '@/components/ui/button'

defineProps<{
  modelValue: string
  options: { value: string; icon: Component; label: string }[]
}>()

const emit = defineEmits<{
  'update:modelValue': [string]
}>()
</script>

<template>
  <div class="flex shrink-0">
    <Button
      v-for="option in options"
      :key="option.value"
      :variant="modelValue === option.value ? 'default' : 'ghost'"
      size="icon-sm"
      class="touch-manipulation"
      :aria-pressed="modelValue === option.value"
      :aria-label="option.label"
      @click="emit('update:modelValue', option.value)"
    >
      <component :is="option.icon" class="size-4" />
    </Button>
  </div>
</template>
