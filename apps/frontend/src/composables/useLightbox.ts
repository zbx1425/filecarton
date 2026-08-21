import { reactive } from 'vue'

export const lightboxState = reactive({
  open: false,
  src: '',
  alt: '',
})

export function openLightbox(src: string, alt: string) {
  lightboxState.src = src
  lightboxState.alt = alt
  lightboxState.open = true
}

export function closeLightbox() {
  lightboxState.open = false
}
