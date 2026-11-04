import { markRaw } from 'vue'
import { Upload as TusUpload, DetailedError } from 'tus-js-client'
import type { HttpStack, HttpRequest } from 'tus-js-client'
import { DefaultHttpStack } from 'tus-js-client'
import { ApiError, buildTusEndpointUrl, getCsrfToken } from '@/api/client'
import { joinPath } from '@/utils/path'
import { sanitizeFileName } from '@/utils/pathValidation'
import type { UploadTask } from '@/stores/upload'

function createPostOnlyHttpStack(): HttpStack {
  const inner = new DefaultHttpStack({})

  return {
    getName(): string {
      return 'PostOnlyHttpStack'
    },

    createRequest(method: string, url: string): HttpRequest {
      const upperMethod = method.toUpperCase()
      if (upperMethod === 'POST') {
        return inner.createRequest('POST', url)
      }

      const req = inner.createRequest('POST', url)
      req.setHeader('X-HTTP-Method-Override', upperMethod)
      return req
    },
  }
}

/**
 * Upload a single file via the TUS resumable-upload protocol.
 * Mutates `task.progress`, `task.tusUpload`, and `task.status` (on cancel).
 */
export function uploadViaTus(
  task: UploadTask,
  options: { chunkSize: number },
): Promise<void> {
  let targetDir = task.targetDir
  if (task.relativePath) {
    const dir = task.relativePath.split('/').slice(0, -1).join('/')
    if (dir) targetDir = joinPath(task.targetDir, dir)
  }

  return new Promise<void>((resolve, reject) => {
    const upload = new TusUpload(task.file, {
      endpoint: buildTusEndpointUrl(),
      chunkSize: options.chunkSize,
      retryDelays: [0, 1000, 3000, 5000],
      uploadDataDuringCreation: true,
      overridePatchMethod: true,
      httpStack: createPostOnlyHttpStack(),
      storeFingerprintForResuming: false,
      removeFingerprintOnSuccess: true,
      headers: {
        'X-CSRF-Token': getCsrfToken(),
      },
      metadata: {
        filename: sanitizeFileName(task.file.name) || task.file.name,
        targetPath: targetDir,
      },
      onProgress(bytesSent, bytesTotal) {
        task.progress = bytesTotal === 0 ? 100 : Math.round((bytesSent / bytesTotal) * 100)
      },
      onSuccess() {
        task.tusUpload = undefined
        task.progress = 100
        resolve()
      },
      onError(error) {
        task.tusUpload = undefined
        if (task.status === 'cancelled') {
          resolve()
          return
        }
        if (error instanceof DetailedError) {
          const resp = error.originalResponse
          if (resp) {
            const status = resp.getStatus()
            let body: any = null
            try { body = JSON.parse(resp.getBody()) } catch { /* non-JSON */ }
            const fallback = status === 413 ? 'upload.content_too_large'
              : status === 403 ? 'access_denied'
                : 'server_error'
            const code = body?.error ?? fallback
            reject(new ApiError(code, status, body?.params, body?.authError))
            return
          }
        }
        reject(new ApiError('network_error', 0))
      },
      onShouldRetry(error) {
        if (task.status === 'cancelled') return false
        const resp = error.originalResponse
        if (!resp) return true
        const status = resp.getStatus()
        if (status === 409) {
          try {
            const body = JSON.parse(resp.getBody())
            if (body?.error) return false
          } catch { /* non-JSON body = offset mismatch, retryable */ }
          return true
        }
        return status === 423 || status === 429 || status >= 500
      },
    })
    task.tusUpload = markRaw(upload)
    upload.start()
  })
}
