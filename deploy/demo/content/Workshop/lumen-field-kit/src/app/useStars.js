// Original demo code, CC0.

export function visible(stars, limit = 6) {
  return stars
    .filter((star) => star.magnitude < limit)
    .sort((a, b) => a.magnitude - b.magnitude)
}

export function formatMagnitude(value) {
  const sign = value < 0 ? "−" : ""
  return sign + Math.abs(value).toFixed(2)
}
