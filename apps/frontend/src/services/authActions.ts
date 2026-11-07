import { apiPost } from '@/api/client'
import { checkDirty, useNavigationStore } from '@/stores/navigation'
import { useUploadStore } from '@/stores/upload'

export async function logout() {
  const navigation = useNavigationStore()
  const upload = useUploadStore()
  if (navigation.editDirty) {
    const leave = await checkDirty(navigation.editDirty)
    if (!leave) return
  }
  navigation.editDirty = false
  if (upload.hasActive) upload.cancelAll()
  try {
    await apiPost('auth_logout', {})
  } catch { /* session already invalid */ }
  location.reload()
}
