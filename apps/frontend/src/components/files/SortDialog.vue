<script setup lang="ts">
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

type SortColumn = 'name' | 'size' | 'mtime'

defineProps<{
  open: boolean
  sortBy: SortColumn
  sortAsc: boolean
}>()

const emit = defineEmits<{
  'update:open': [boolean]
  'update:sortBy': [SortColumn]
  'update:sortAsc': [boolean]
}>()

const columns: { value: SortColumn; label: string }[] = [
  { value: 'name', label: 'common.field.name' },
  { value: 'size', label: 'common.field.size' },
  { value: 'mtime', label: 'common.field.modified' },
]
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>{{ $t('files.action.sort') }}</DialogTitle>
        <DialogDescription class="sr-only">{{ $t('files.action.sort') }}</DialogDescription>
      </DialogHeader>
      <div class="flex gap-1">
        <Button
          v-for="column in columns"
          :key="column.value"
          size="sm"
          class="flex-1"
          :variant="sortBy === column.value ? 'default' : 'outline'"
          @click="emit('update:sortBy', column.value)"
        >
          {{ $t(column.label) }}
        </Button>
      </div>
      <div class="flex gap-1">
        <Button
          size="sm"
          class="flex-1"
          :variant="sortAsc ? 'default' : 'outline'"
          @click="emit('update:sortAsc', true)"
        >
          {{ $t('files.sort.asc') }}
        </Button>
        <Button
          size="sm"
          class="flex-1"
          :variant="!sortAsc ? 'default' : 'outline'"
          @click="emit('update:sortAsc', false)"
        >
          {{ $t('files.sort.desc') }}
        </Button>
      </div>
    </DialogContent>
  </Dialog>
</template>
