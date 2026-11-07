<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { ArrowLeft, Folder, FolderTree } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import IconSegmentedControl from './IconSegmentedControl.vue'

defineProps<{
  modelValue: string
  recursive: boolean
  placeholder: string
}>()

const emit = defineEmits<{
  'update:modelValue': [string]
  'update:recursive': [boolean]
  back: []
}>()

const { t } = useI18n()
</script>

<template>
  <div class="flex min-w-0 flex-1 items-center gap-2 pe-2">
    <Button
      variant="ghost"
      size="icon-sm"
      class="size-11 shrink-0 touch-manipulation"
      :aria-label="$t('common.action.back')"
      @click="emit('back')"
    >
      <ArrowLeft class="size-4" />
    </Button>
    <Input
      :model-value="modelValue"
      class="h-8 min-w-0 flex-1 text-base -ms-2"
      :placeholder="placeholder"
      @update:model-value="emit('update:modelValue', String($event))"
    />
    <IconSegmentedControl
      :model-value="recursive ? 'sub' : 'here'"
      :options="[
        { value: 'here', icon: Folder, label: t('files.search.scope_here') },
        { value: 'sub', icon: FolderTree, label: t('files.search.scope_subfolders') },
      ]"
      @update:model-value="emit('update:recursive', $event === 'sub')"
    />
  </div>
</template>
