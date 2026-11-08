// Original demo code, CC0. Not a real component runtime.
import type { Star } from "../../types"

export function SkyChart(props: { stars: Star[] }) {
  const brightest = [...props.stars].sort((a, b) => a.magnitude - b.magnitude)[0]
  return (
    <figure className="sky-chart">
      <figcaption>{brightest ? brightest.name : "empty sky"}</figcaption>
      <ul>
        {props.stars.map((star) => (
          <li key={star.name}>
            {star.zh} {star.name}
          </li>
        ))}
      </ul>
    </figure>
  )
}
