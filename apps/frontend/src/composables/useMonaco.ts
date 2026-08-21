import type * as Monaco from 'monaco-editor'

let monacoPromise: Promise<typeof Monaco> | null = null

export function loadMonaco(): Promise<typeof Monaco> {
  if (!monacoPromise) {
    monacoPromise = import('monaco-editor')
  }
  return monacoPromise
}
