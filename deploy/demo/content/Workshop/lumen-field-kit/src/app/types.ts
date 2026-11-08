// Original demo code, CC0.

export interface Star {
  name: string
  bayer: string
  magnitude: number
  zh: string
}

export type SortKey = "name" | "magnitude"

export function compareStars(key: SortKey) {
  return (a: Star, b: Star): number => {
    if (key === "magnitude") return a.magnitude - b.magnitude
    return a.name.localeCompare(b.name, undefined, { numeric: true })
  }
}
