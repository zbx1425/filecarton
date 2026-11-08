const closers = new Set<() => void>()

export function registerAnchoredMenu(close: () => void) {
  closers.add(close)
  return {
    closeOthers() {
      for (const closeOther of closers) {
        if (closeOther !== close) closeOther()
      }
    },
    unregister() {
      closers.delete(close)
    },
  }
}
