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

export function maybeUnauthorized(action: string, error: ApiError) {
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
    let code = 'server_error'
    let params: Record<string, string | number> | undefined = { status: response.status }
    let authError: string | undefined
    try {
      const body = await response.json()
      if (body?.error) { code = body.error; params = body.params }
      if (body?.authError) authError = body.authError
    } catch { /* non-JSON error body */ }
    throw new ApiError(code, response.status, params, authError)
  }
  const body = await response.json()
  if (!body.ok) {
    throw new ApiError(body.error ?? 'server_error', response.status, body.params)
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

export async function apiWrite(
  path: string,
  content: string,
  expectedMtime?: number,
): Promise<{ size: number; mtime: number }> {
  const { csrfToken } = getConfig()
  const params: Record<string, string> = { path }
  if (expectedMtime !== undefined) {
    params.expectedMtime = String(expectedMtime)
  }
  const url = buildUrl('write', params)
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/octet-stream',
      'X-CSRF-Token': csrfToken,
    },
    body: content,
  })
  try {
    return await handleResponse<{ size: number; mtime: number }>(response)
  } catch (e) {
    if (e instanceof ApiError) maybeUnauthorized('write', e)
    throw e
  }
}

export function buildRawUrl(filePath: string): string {
  return buildUrl('raw', { path: filePath })
}

export function buildDownloadUrl(filePath: string): string {
  return buildUrl('download', { path: filePath })
}

export function buildArchiveRawUrl(archivePath: string, entryPath: string): string {
  return buildUrl('archive_raw', { path: archivePath, entry: entryPath })
}

export function buildArchiveDownloadUrl(archivePath: string, entryPath: string): string {
  return buildUrl('archive_download', { path: archivePath, entry: entryPath })
}

export async function fetchBatchDownload(dirPath: string, items: string[]): Promise<void> {
  // Step 1: POST to validate and obtain a one-time download token
  const { token } = await apiPost<{ token: string }>('batch_download', { path: dirPath, items })

  // Step 2: Trigger browser-native download via GET with the token
  const downloadUrl = buildUrl('batch_download', { token })
  const a = document.createElement('a')
  a.href = downloadUrl
  a.download = ''
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
}

export function buildTusEndpointUrl(): string {
  return new URL(buildUrl('tus'), window.location.href).href
}

export function getCsrfToken(): string {
  return getConfig().csrfToken
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
