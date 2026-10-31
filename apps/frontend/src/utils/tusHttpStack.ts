import { DefaultHttpStack } from 'tus-js-client'
import type { HttpStack, HttpRequest } from 'tus-js-client'

/**
 * POST-only TUS HTTP stack.
 *
 * FileCarton's backend only accepts POST requests (shared hosting often blocks
 * PATCH/DELETE via mod_security etc.). tus-js-client's `overridePatchMethod`
 * converts PATCH→POST but HEAD and DELETE still go out as-is.
 *
 * This wrapper converts ALL non-POST methods into POST +
 * `X-HTTP-Method-Override` so the server can dispatch on the override header.
 */
export function createPostOnlyHttpStack(): HttpStack {
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
