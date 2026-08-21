export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

function getConfig() {
  return window.__FILECARTON__
}

function buildUrl(action: string, params?: Record<string, string>): string {
  const { apiBase } = getConfig()
  const searchParams = new URLSearchParams({ api: '1', action, ...params })
  return `${apiBase}/?${searchParams}`
}

async function handleResponse<T>(response: Response): Promise<T> {
  if (!response.ok) {
    let message = `HTTP ${response.status}`
    try {
      const body = await response.json()
      if (body?.error) message = body.error
    } catch { /* non-JSON error body */ }
    throw new ApiError(message, response.status)
  }
  const body = await response.json()
  if (!body.ok) {
    throw new ApiError(body.error ?? 'Unknown error', response.status)
  }
  return body.data as T
}

export async function apiGet<T>(action: string, params?: Record<string, string>): Promise<T> {
  const url = buildUrl(action, params)
  const response = await fetch(url)
  return handleResponse<T>(response)
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
  return handleResponse<T>(response)
}

export async function apiUpload<T>(action: string, formData: FormData): Promise<T> {
  const { csrfToken } = getConfig()
  const url = buildUrl(action)
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrfToken },
    body: formData,
  })
  return handleResponse<T>(response)
}

export function buildRawUrl(filePath: string): string {
  return buildUrl('raw', { path: filePath })
}

export function buildDownloadUrl(filePath: string): string {
  return buildUrl('download', { path: filePath })
}
