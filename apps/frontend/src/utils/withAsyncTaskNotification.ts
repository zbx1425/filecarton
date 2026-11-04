import { toast } from 'vue-sonner'
import { errorMessage } from '@/utils/errorMessage'

/**
 * Execute an async operation with loading/success/error toast notifications.
 *
 * Covers the common "Pattern A" toast lifecycle:
 *   1. Show a loading toast
 *   2. On success → replace with success toast
 *   3. On error → replace with error toast (translated via errorMessage)
 *
 * @returns The operation result on success, or `undefined` on error.
 */
export async function withAsyncTaskNotification<T>(
  operation: () => Promise<T>,
  messages: {
    loading: string
    success: string | ((result: T) => string)
  },
): Promise<T | undefined> {
  const toastId = toast.loading(messages.loading)
  try {
    const result = await operation()
    const msg = typeof messages.success === 'function'
      ? messages.success(result) : messages.success
    toast.success(msg, { id: toastId })
    return result
  } catch (e) {
    toast.error(errorMessage(e), { id: toastId })
    return undefined
  }
}
