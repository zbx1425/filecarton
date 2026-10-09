export class ApiError extends Error {
  code: string
  status: number
  params?: Record<string, string | number>
  authError?: string

  constructor(
    code: string,
    status: number,
    params?: Record<string, string | number>,
    authError?: string,
  ) {
    super(code)
    this.name = 'ApiError'
    this.code = code
    this.status = status
    this.params = params
    this.authError = authError
  }
}

export interface ErrorInfo {
  code: string
  params?: Record<string, string | number>
}

export function toErrorInfo(e: unknown): ErrorInfo {
  if (e instanceof ApiError) {
    return { code: e.code, params: e.params }
  }
  return { code: 'server_error' }
}

let unauthorizedHandler: ((action: string, authError: string) => void) | null = null

export function onUnauthorized(handler: (action: string, authError: string) => void) {
  unauthorizedHandler = handler
}

function maybeUnauthorized(action: string, error: ApiError) {
  if (!error.authError) return
  if (!window.__FILECARTON__.auth?.enabled) return
  if (action === 'auth_login' || action === 'auth_logout') return
  unauthorizedHandler?.(action, error.authError)
}

function getConfig() {
  return window.__FILECARTON__
}

let cachedPassthrough: URLSearchParams | null = null

function getPassthroughParams(): URLSearchParams {
  if (cachedPassthrough) return cachedPassthrough
  const current = new URLSearchParams(window.location.search)
  const result = new URLSearchParams()
  for (const [key, value] of current) {
    if (key === 'state' || key.startsWith('state_') || key.startsWith('state[')) {
      result.append(key, value)
    }
  }
  cachedPassthrough = result
  return result
}

function buildUrl(action: string, params?: Record<string, string>): string {
  const { apiBase } = getConfig()
  const url = new URL(apiBase, window.location.href)
  url.hash = ''
  for (const [k, v] of getPassthroughParams()) url.searchParams.append(k, v)
  url.searchParams.set('fcapi', action)
  if (params) for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v)
  return url.pathname + url.search
}

async function handleResponse<T>(response: Response): Promise<T> {
  if (!response.ok) {
    let code = `http_${response.status}`
    let params: Record<string, string | number> | undefined
    let authError: string | undefined
    try {
      const body = await response.json()
      if (body?.error) code = body.error
      if (body?.params) params = body.params
      if (body?.authError) authError = body.authError
    } catch { /* non-JSON error body */ }
    throw new ApiError(code, response.status, params, authError)
  }
  const body = await response.json()
  if (!body.ok) {
    throw new ApiError(body.error ?? 'unknown', response.status, body.params)
  }
  return body.data as T
}

export async function apiGet<T>(action: string, params?: Record<string, string>, signal?: AbortSignal): Promise<T> {
  const url = buildUrl(action, params)
  const response = await fetch(url, signal ? { signal } : undefined)
  try {
    return await handleResponse<T>(response)
  } catch (e) {
    if (e instanceof ApiError) maybeUnauthorized(action, e)
    throw e
  }
}

export async function apiPost<T>(action: string, body: unknown): Promise<T> {
  const { csrfToken } = getConfig()
  const url = buildUrl(action)
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    body: JSON.stringify(body),
  })
  try {
    return await handleResponse<T>(response)
  } catch (e) {
    if (e instanceof ApiError) maybeUnauthorized(action, e)
    throw e
  }
}

export function apiUpload<T>(
  action: string,
  formData: FormData,
  onProgress?: (loaded: number, total: number) => void,
): Promise<T> {
  const { csrfToken } = getConfig()
  const url = buildUrl(action)

  return new Promise<T>((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open('POST', url)
    xhr.setRequestHeader('X-CSRF-Token', csrfToken)

    if (onProgress) {
      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) onProgress(e.loaded, e.total)
      })
    }

    xhr.addEventListener('load', () => {
      try {
        const body = JSON.parse(xhr.responseText)
        if (xhr.status < 200 || xhr.status >= 300) {
          const err = new ApiError(body?.error ?? `http_${xhr.status}`, xhr.status, body?.params, body?.authError)
          maybeUnauthorized(action, err)
          reject(err)
          return
        }
        if (!body.ok) {
          reject(new ApiError(body.error ?? 'unknown', xhr.status, body.params))
          return
        }
        resolve(body.data as T)
      } catch {
        reject(new ApiError(`http_${xhr.status}`, xhr.status))
      }
    })

    xhr.addEventListener('error', () => {
      reject(new ApiError('network_error', 0))
    })

    xhr.send(formData)
  })
}

export function apiWrite(
  path: string,
  content: string,
  expectedMtime?: number,
): Promise<{ size: number; mtime: number }> {
  const formData = new FormData()
  formData.append('path', path)
  formData.append(
    'content',
    new Blob([content], { type: 'application/octet-stream' }),
    'content',
  )
  if (expectedMtime !== undefined) {
    formData.append('expectedMtime', String(expectedMtime))
  }
  return apiUpload('write', formData)
}

export function buildRawUrl(filePath: string): string {
  return buildUrl('raw', { path: filePath })
}

export function buildDownloadUrl(filePath: string): string {
  return buildUrl('download', { path: filePath })
}

export function buildAuthStartUrl(pluginId: string): string {
  const { apiBase } = getConfig()
  const url = new URL(apiBase, window.location.href)
  url.hash = ''
  for (const [k, v] of getPassthroughParams()) url.searchParams.append(k, v)
  url.searchParams.set('fcauth', 'start')
  url.searchParams.set('plugin', pluginId)
  const hash = window.location.hash
  if (hash && hash !== '#' && hash !== '#/') {
    url.searchParams.set('fc_return_hash', hash.slice(1))
  }
  return url.pathname + url.search
}
