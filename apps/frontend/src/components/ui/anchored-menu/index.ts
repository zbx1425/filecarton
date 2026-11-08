import type { InjectionKey } from 'vue'

export { default as AnchoredMenu } from './AnchoredMenu.vue'

export interface AnchoredMenuHandle {
  isExpanded(key: string): boolean
  onPointerDown(key: string, event: PointerEvent): void
  onClick(key: string, event: MouseEvent, commitTarget: () => void): void
  release(key: string): void
}

export const ANCHORED_MENU_KEY: InjectionKey<AnchoredMenuHandle> = Symbol('anchoredMenu')
