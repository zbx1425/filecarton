<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { toast } from 'vue-sonner'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import { Separator } from '@/components/ui/separator'
import { dialogState, confirm, showOperationReport } from '@/services/dialogs'
import { useUiStore } from '@/stores/ui'
import { handleMutation } from '@/services/treeInvalidation'
import { apiPost } from '@/api/client'
import type { ChmodResponse } from '@/api/types'
import { errorMessage } from '@/utils/errorMessage'

type BitState = boolean | 'indeterminate'

const BIT_DEFS = [
  // Row 0: Read + SUID
  { key: 'ur', mask: 0o0400 },
  { key: 'gr', mask: 0o0040 },
  { key: 'or', mask: 0o0004 },
  { key: 'suid', mask: 0o4000 },
  // Row 1: Write + SGID
  { key: 'uw', mask: 0o0200 },
  { key: 'gw', mask: 0o0020 },
  { key: 'ow', mask: 0o0002 },
  { key: 'sgid', mask: 0o2000 },
  // Row 2: Execute + Sticky
  { key: 'ux', mask: 0o0100 },
  { key: 'gx', mask: 0o0010 },
  { key: 'ox', mask: 0o0001 },
  { key: 'sticky', mask: 0o1000 },
] as const

type BitKey = typeof BIT_DEFS[number]['key']

const ROWS: Array<{
  labelKey: string
  cols: [BitKey, BitKey, BitKey]
}> = [
  { labelKey: 'permissions.bit.read', cols: ['ur', 'gr', 'or'] },
  { labelKey: 'permissions.bit.write', cols: ['uw', 'gw', 'ow'] },
  { labelKey: 'permissions.bit.execute', cols: ['ux', 'gx', 'ox'] },
]

const { t } = useI18n()
const ui = useUiStore()

const state = computed(() => dialogState.permissions)
const items = computed(() => state.value.items)
const isRoot = computed(() => ui.unixPermInfo.isRoot)
const processUser = computed(() => ui.unixPermInfo.user)

const initialBits = ref<Record<BitKey, BitState>>({
  ur: false, uw: false, ux: false,
  gr: false, gw: false, gx: false,
  or: false, ow: false, ox: false,
  suid: false, sgid: false, sticky: false,
})

const currentBits = ref<Record<BitKey, BitState>>({
  ur: false, uw: false, ux: false,
  gr: false, gw: false, gx: false,
  or: false, ow: false, ox: false,
  suid: false, sgid: false, sticky: false,
})

const octalInput = ref('')
const octalApplied = ref(false)
const initialOwner = ref<string | null>(null)
const initialGroup = ref<string | null>(null)
const ownerInput = ref('')
const groupInput = ref('')
const submitting = ref(false)

const hasDirectory = computed(() => items.value.some(i => i.isDir))

const dialogTitle = computed(() => {
  if (items.value.length === 1) {
    return t('permissions.title_one', { name: items.value[0].name })
  }
  return t('permissions.title_many', { count: items.value.length }, items.value.length)
})

const headerOwnerSub = computed(() => {
  if (initialOwner.value === null) return t('permissions.mixed')
  return initialOwner.value || '—'
})

const headerGroupSub = computed(() => {
  if (initialGroup.value === null) return t('permissions.mixed')
  return initialGroup.value || '—'
})

const hasIndeterminate = computed(() =>
  BIT_DEFS.some(d => currentBits.value[d.key] === 'indeterminate'),
)

function computeOctalFromBits(): string {
  if (hasIndeterminate.value) return ''
  let mode = 0
  for (const def of BIT_DEFS) {
    if (currentBits.value[def.key] === true) {
      mode |= def.mask
    }
  }
  if (mode & 0o7000) return mode.toString(8).padStart(4, '0')
  return (mode & 0o777).toString(8).padStart(3, '0')
}

const symbolicPreview = computed(() => {
  const b = currentBits.value
  const ch = (bit: BitState, c: string) => bit === 'indeterminate' ? '?' : (bit ? c : '-')
  const execCh = (execBit: BitState, specBit: BitState, specLower: string, specUpper: string) => {
    if (execBit === 'indeterminate' || specBit === 'indeterminate') return '?'
    if (specBit) return execBit ? specLower : specUpper
    return execBit ? 'x' : '-'
  }
  return [
    ch(b.ur, 'r'),
    ch(b.uw, 'w'),
    execCh(b.ux, b.suid, 's', 'S'),
    ch(b.gr, 'r'),
    ch(b.gw, 'w'),
    execCh(b.gx, b.sgid, 's', 'S'),
    ch(b.or, 'r'),
    ch(b.ow, 'w'),
    execCh(b.ox, b.sticky, 't', 'T'),
  ].join('')
})

function initFromItems() {
  const list = items.value
  octalApplied.value = false
  submitting.value = false

  if (list.length === 0) return

  const newInit: Record<BitKey, BitState> = {
    ur: false, uw: false, ux: false,
    gr: false, gw: false, gx: false,
    or: false, ow: false, ox: false,
    suid: false, sgid: false, sticky: false,
  }

  for (const def of BIT_DEFS) {
    let allTrue = true
    let allFalse = true
    for (const item of list) {
      if (item.mode === undefined) {
        allTrue = false
        allFalse = false
        break
      }
      const set = (item.mode & def.mask) !== 0
      if (set) allFalse = false
      else allTrue = false
    }
    if (allTrue) newInit[def.key] = true
    else if (allFalse) newInit[def.key] = false
    else newInit[def.key] = 'indeterminate'
  }

  initialBits.value = { ...newInit }
  currentBits.value = { ...newInit }
  octalInput.value = computeOctalFromBits()

  // Owner & Group
  const owners = new Set(list.map(i => i.owner ?? ''))
  if (owners.size === 1) {
    const val = Array.from(owners)[0]
    initialOwner.value = val
    ownerInput.value = val
  } else {
    initialOwner.value = null
    ownerInput.value = ''
  }

  const groups = new Set(list.map(i => i.group ?? ''))
  if (groups.size === 1) {
    const val = Array.from(groups)[0]
    initialGroup.value = val
    groupInput.value = val
  } else {
    initialGroup.value = null
    groupInput.value = ''
  }
}

watch(() => state.value.open, (open) => {
  if (open) initFromItems()
})

function toggleBit(key: BitKey) {
  const cur = currentBits.value[key]
  // Indeterminate -> true -> false -> true
  currentBits.value[key] = cur === true ? false : true
  octalInput.value = computeOctalFromBits()
}

function handleOctalInput(e: Event) {
  const target = e.target as HTMLInputElement
  const cleaned = target.value.replace(/[^0-7]/g, '').slice(0, 4)
  if (target.value !== cleaned) {
    target.value = cleaned
  }
  octalInput.value = cleaned

  if (cleaned.length >= 3) {
    const parsed = parseInt(cleaned, 8)
    for (const def of BIT_DEFS) {
      currentBits.value[def.key] = (parsed & def.mask) !== 0
    }
    octalApplied.value = true
  }
}

function handleOctalBlur() {
  if (octalInput.value.length >= 3) {
    const parsed = parseInt(octalInput.value, 8)
    if (parsed & 0o7000) {
      octalInput.value = parsed.toString(8).padStart(4, '0')
    } else {
      octalInput.value = (parsed & 0o777).toString(8).padStart(3, '0')
    }
  } else {
    octalInput.value = computeOctalFromBits()
  }
}

const masks = computed(() => {
  let setMask = 0
  let clearMask = 0
  for (const def of BIT_DEFS) {
    const cur = currentBits.value[def.key]
    const init = initialBits.value[def.key]
    if (cur !== 'indeterminate' && (cur !== init || octalApplied.value)) {
      if (cur === true) setMask |= def.mask
      else clearMask |= def.mask
    }
  }
  return { setMask, clearMask }
})

const isModeDirty = computed(() => masks.value.setMask !== 0 || masks.value.clearMask !== 0)

const isOwnerDirty = computed(() => {
  if (!isRoot.value) return false
  const trimmed = ownerInput.value.trim()
  if (!trimmed) return false
  return trimmed !== initialOwner.value
})

const isGroupDirty = computed(() => {
  if (!isRoot.value) return false
  const trimmed = groupInput.value.trim()
  if (!trimmed) return false
  return trimmed !== initialGroup.value
})

const isAnyDirty = computed(() => isModeDirty.value || isOwnerDirty.value || isGroupDirty.value)

function closeDialog() {
  dialogState.permissions.open = false
}

async function applyPermissions(recursive: boolean) {
  if (!isAnyDirty.value || submitting.value) return

  if (recursive) {
    const dirCount = items.value.filter(i => i.isDir).length
    const msg = dirCount === 1 && items.value.length === 1
      ? t('permissions.confirm_recursive_one', { name: items.value[0].name })
      : t('permissions.confirm_recursive_many', { count: dirCount }, dirCount)
    const confirmed = await confirm(t('permissions.apply_recursive'), msg, {
      actionLabel: t('permissions.apply_recursive'),
      danger: true,
    })
    if (!confirmed) return
  }

  submitting.value = true

  const dirPath = state.value.dirPath
  const itemNames = items.value.map(i => i.name)

  const payload: Record<string, unknown> = {
    path: dirPath,
    items: itemNames,
    recursive,
  }

  if (isModeDirty.value) {
    payload.setMask = masks.value.setMask
    payload.clearMask = masks.value.clearMask
  }
  if (isOwnerDirty.value) {
    payload.owner = ownerInput.value.trim()
  }
  if (isGroupDirty.value) {
    payload.group = groupInput.value.trim()
  }

  const toastId = toast.loading(t('toast.perm_updating'))

  try {
    const result = await apiPost<ChmodResponse>('chmod', payload)
    closeDialog()

    if (result.failed.length > 0 && result.completed === 0) {
      toast.dismiss(toastId)
      await showOperationReport({
        title: t('permissions.report.failed_title'),
        description: t('permissions.report.failed'),
        items: result.failed.map(f => ({ name: f.name, reason: f.error })),
      })
    } else if (result.failed.length > 0) {
      toast.dismiss(toastId)
      await showOperationReport({
        title: t('permissions.report.partial_title'),
        description: t('permissions.report.partial', { completed: result.completed, failed: result.failed.length }),
        items: result.failed.map(f => ({ name: f.name, reason: f.error })),
      })
    } else {
      toast.success(t('toast.perm_updated', { count: result.completed }, result.completed), { id: toastId })
    }

    handleMutation(
      recursive ? { subtrees: [dirPath] } : { dirs: [dirPath] },
    )
  } catch (e: unknown) {
    closeDialog()
    toast.error(errorMessage(e), { id: toastId })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Dialog :open="state.open" @update:open="(v: boolean) => { if (!v) closeDialog() }">
    <DialogContent>
      <DialogHeader>
        <DialogTitle class="truncate pr-6">{{ dialogTitle }}</DialogTitle>
        <DialogDescription class="sr-only">
          {{ $t('permissions.description') }}
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-4 py-1">
        <!-- Section 1: File mode (left 3x3 grid + right Octal box) -->
        <div class="space-y-2">
          <div class="flex flex-col sm:flex-row gap-4 items-stretch">
            <!-- Left: 3x3 Checkbox Grid -->
            <div class="flex-1 rounded-md border bg-muted/15 p-3">
              <div class="grid grid-cols-3 gap-x-2 gap-y-2.5 items-center">
                <!-- Header row -->
                <div class="min-w-0">
                  <div class="text-sm font-medium text-foreground">{{ $t('common.field.owner') }}</div>
                  <div class="text-sm text-muted-foreground truncate" :title="headerOwnerSub">
                    {{ headerOwnerSub }}
                  </div>
                </div>
                <div class="min-w-0">
                  <div class="text-sm font-medium text-foreground">{{ $t('common.field.group') }}</div>
                  <div class="text-sm text-muted-foreground truncate" :title="headerGroupSub">
                    {{ headerGroupSub }}
                  </div>
                </div>
                <div class="min-w-0 self-start">
                  <div class="text-sm font-medium text-foreground">{{ $t('permissions.other') }}</div>
                </div>

                <!-- 3 Permission rows -->
                <template v-for="row in ROWS" :key="row.labelKey">
                  <label
                    v-for="colKey in row.cols"
                    :key="colKey"
                    class="flex items-center gap-1.5 text-sm cursor-pointer select-none"
                  >
                    <Checkbox
                      :model-value="currentBits[colKey]"
                      @update:model-value="toggleBit(colKey)"
                    />
                    <span>{{ $t(row.labelKey) }}</span>
                  </label>
                </template>
              </div>
            </div>

            <!-- Right: Octal Quick Input -->
            <div class="sm:w-32 shrink-0 rounded-md border bg-muted/15 p-3 flex flex-col justify-between gap-2">
              <div class="space-y-1.5">
                <label class="text-sm font-medium text-foreground block">{{ $t('permissions.octal') }}</label>
                <Input
                  :model-value="octalInput"
                  :placeholder="$t('permissions.mixed')"
                  maxlength="4"
                  class="font-mono text-center h-8 text-sm tracking-widest px-2"
                  @input="handleOctalInput"
                  @blur="handleOctalBlur"
                  @keydown.enter.prevent="handleOctalBlur"
                />
              </div>
              <div>
                <div class="text-sm text-muted-foreground mb-0.5">{{ $t('permissions.preview') }}</div>
                <div class="font-mono text-center text-sm text-foreground tracking-wider bg-background/80 border rounded px-1.5 py-0.5">
                  {{ symbolicPreview }}
                </div>
              </div>
            </div>
          </div>
        </div>

        <Separator />

        <!-- Section 2 & 3: Running user notice & Ownership form -->
        <div class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-foreground block">{{ $t('common.field.owner') }}</label>
              <Input
                v-model="ownerInput"
                :disabled="!isRoot"
                :placeholder="initialOwner === null ? $t('permissions.mixed') : $t('common.field.owner')"
                class="h-8 text-sm"
              />
            </div>
            <div class="space-y-1.5">
              <label class="text-sm font-medium text-foreground block">{{ $t('common.field.group') }}</label>
              <Input
                v-model="groupInput"
                :disabled="!isRoot"
                :placeholder="initialGroup === null ? $t('permissions.mixed') : $t('common.field.group')"
                class="h-8 text-sm"
              />
            </div>
          </div>
          <i18n-t keypath="permissions.running_as" tag="div" class="text-sm text-muted-foreground">
            <template #user>
              <strong class="text-foreground font-medium">{{ processUser }}</strong>
            </template>
          </i18n-t>
        </div>
      </div>

      <DialogFooter class="mt-2 flex-row justify-end gap-2">
        <Button variant="outline" type="button" @click="closeDialog">
            {{ $t('common.action.cancel') }}
        </Button>
        <Button
          v-if="hasDirectory"
          type="button"
          variant="secondary"
          :disabled="!isAnyDirty || submitting"
          @click="applyPermissions(true)"
        >
          {{ $t('permissions.apply_recursive') }}
        </Button>
        <Button
          type="button"
          :disabled="!isAnyDirty || submitting"
          @click="applyPermissions(false)"
        >
          {{ $t('common.action.apply') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
