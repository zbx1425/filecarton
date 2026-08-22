import type * as Monaco from 'monaco-editor'

const MONACO_VERSION = '0.56.0'
const MONACO_CDN = `https://fastly.jsdelivr.net/npm/monaco-editor@${MONACO_VERSION}`
const MONACO_VS = `${MONACO_CDN}/min/vs`

let monacoPromise: Promise<typeof Monaco> | null = null

function loadScript(src: string): Promise<void> {
  return new Promise((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) {
      resolve()
      return
    }
    const script = document.createElement('script')
    script.src = src
    script.onload = () => resolve()
    script.onerror = reject
    document.head.appendChild(script)
  })
}

export function loadMonaco(): Promise<typeof Monaco> {
  if (!monacoPromise) {
    monacoPromise = (async () => {
      await loadScript(`${MONACO_VS}/loader.js`)

      const require = (window as any).require
      require.config({ paths: { vs: MONACO_VS } })

      ;(window as any).MonacoEnvironment = {
        getWorkerUrl(_workerId: string, _label: string) {
          return `data:text/javascript;charset=utf-8,${encodeURIComponent(`
            self.MonacoEnvironment = { baseUrl: '${MONACO_CDN}/min/' };
            importScripts('${MONACO_VS}/base/worker/workerMain.js');
          `)}`
        },
      }

      return new Promise<typeof Monaco>((resolve) => {
        require(['vs/editor/editor.main'], (monaco: typeof Monaco) => {
          resolve(monaco)
        })
      })
    })()
  }
  return monacoPromise
}
