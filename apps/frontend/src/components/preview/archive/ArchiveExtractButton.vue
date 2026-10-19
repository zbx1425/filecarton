<script setup lang="ts">
import { computed } from 'vue'
import { PackageOpen, ChevronDown } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useUiStore } from '@/stores/ui'
import { extractArchive } from '@/composables/useArchive'
import { archiveSubdirName, parentPath, pathName } from '@/utils/path'

const props = defineProps<{
  path: string
  name?: string
}>()

const ui = useUiStore()

const archiveName = computed(() => props.name || pathName(props.path))
const subdirName = computed(() => archiveSubdirName(archiveName.value))
const targetDir = computed(() => parentPath(props.path))

async function handleExtract(createSubdir: boolean) {
  await extractArchive(props.path, targetDir.value, createSubdir)
}
</script>

<template>
  <DropdownMenu v-if="!ui.readonly && ui.features.archive">
    <DropdownMenuTrigger as-child>
      <Button variant="outline" size="sm">
        <PackageOpen class="size-4" />
        Extract
        <ChevronDown class="size-3.5" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end">
      <DropdownMenuItem @select="handleExtract(false)">
        Extract Here
      </DropdownMenuItem>
      <DropdownMenuItem v-if="subdirName" @select="handleExtract(true)">
        Extract to "{{ subdirName }}/"
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
