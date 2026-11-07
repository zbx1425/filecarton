import { computed, toValue, type Component, type MaybeRefOrGetter } from 'vue'
import {
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuShortcut,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
} from '@/components/ui/context-menu'
import {
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu'

export type MenuSurfaceName = 'context' | 'dropdown'

export interface MenuParts {
  Item: Component
  Separator: Component
  Shortcut: Component
  Sub: Component
  SubTrigger: Component
  SubContent: Component
}

const contextParts: MenuParts = {
  Item: ContextMenuItem,
  Separator: ContextMenuSeparator,
  Shortcut: ContextMenuShortcut,
  Sub: ContextMenuSub,
  SubTrigger: ContextMenuSubTrigger,
  SubContent: ContextMenuSubContent,
}

const dropdownParts: MenuParts = {
  Item: DropdownMenuItem,
  Separator: DropdownMenuSeparator,
  Shortcut: DropdownMenuShortcut,
  Sub: DropdownMenuSub,
  SubTrigger: DropdownMenuSubTrigger,
  SubContent: DropdownMenuSubContent,
}

export function useMenuSurface(surface: MaybeRefOrGetter<MenuSurfaceName>) {
  return computed(() => (toValue(surface) === 'dropdown' ? dropdownParts : contextParts))
}
