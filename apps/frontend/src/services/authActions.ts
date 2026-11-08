import { toast } from 'vue-sonner'
import { apiPost } from '@/api/client'
import { translate } from '@/i18n'
import { checkDirty, useNavigationStore } from '@/stores/navigation'
import { useUploadStore } from '@/stores/upload'

let logoutInFlight = false

export async function logout() {
  if (logoutInFlight) return
  const navigation = useNavigationStore()
  const upload = useUploadStore()
  if (navigation.editDirty) {
    const leave = await checkDirty(navigation.editDirty)
    if (!leave) return
  }
  if (logoutInFlight) return
  logoutInFlight = true
  navigation.editDirty = false
  if (upload.hasActive) upload.cancelAll()
  toast.loading(translate('toast.logging_out'))
  try {
    await apiPost('auth_logout', {})
  } catch { /* session already invalid; still reload */ }
  location.reload()
}
