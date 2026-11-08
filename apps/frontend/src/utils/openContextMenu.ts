/** Open the single context menu that wraps a list, from a row button. */
export function openContextMenu(e: MouseEvent) {
  const el = e.currentTarget
  if (!(el instanceof HTMLElement)) return
  el.dispatchEvent(new MouseEvent('contextmenu', {
    bubbles: true,
    cancelable: true,
    clientX: e.clientX,
    clientY: e.clientY,
  }))
}
