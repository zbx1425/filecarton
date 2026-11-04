import { ref, watch, type Ref, type ComputedRef } from 'vue'

/**
 * Reactive upload-speed and ETA calculator.
 *
 * Samples `bytesSent` every 500 ms within a 5-second sliding window.
 * Automatically stops when `isActive` becomes false.
 *
 * @returns `speed` (bytes/s) and `eta` (seconds, -1 = unknown) as readonly refs,
 *          plus `start()` / `stop()` for manual control.
 */
export function useSpeedSampling(
  bytesSent: Ref<number> | ComputedRef<number>,
  totalBytes: Ref<number> | ComputedRef<number>,
  isActive: Ref<boolean> | ComputedRef<boolean>,
) {
  const speed = ref(0)
  const eta = ref(-1)
  let samples: { time: number; bytes: number }[] = []
  let interval: ReturnType<typeof setInterval> | null = null

  function start() {
    if (interval) return
    samples = [{ time: Date.now(), bytes: bytesSent.value }]
    speed.value = 0
    eta.value = -1
    interval = setInterval(() => {
      const now = Date.now()
      const sent = bytesSent.value
      samples.push({ time: now, bytes: sent })
      const cutoff = now - 5000
      while (samples.length > 1 && samples[0].time < cutoff) {
        samples.shift()
      }
      if (samples.length >= 2) {
        const first = samples[0]
        const last = samples[samples.length - 1]
        const dt = (last.time - first.time) / 1000
        if (dt > 0) {
          speed.value = (last.bytes - first.bytes) / dt
          const remaining = totalBytes.value - sent
          eta.value = speed.value > 0 ? remaining / speed.value : -1
        }
      }
      if (!isActive.value) stop()
    }, 500)
  }

  function stop() {
    if (interval) {
      clearInterval(interval)
      interval = null
    }
    samples = []
    speed.value = 0
    eta.value = -1
  }

  watch(isActive, (active) => {
    if (!active && interval) stop()
  })

  return { speed, eta, start, stop } as const
}
