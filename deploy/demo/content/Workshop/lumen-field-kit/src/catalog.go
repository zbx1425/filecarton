// Original demo code, CC0.
package main

import "fmt"

type Star struct {
    Name string
    Mag  float64
}

func faintest(stars []Star) Star {
    best := stars[0]
    for _, star := range stars[1:] {
        if star.Mag > best.Mag {
            best = star
        }
    }
    return best
}

func main() {
    stars := []Star{{"Vega", 0.03}, {"Altair", 0.76}, {"Deneb", 1.25}}
    fmt.Printf("faintest of the Summer Triangle: %s\n", faintest(stars).Name)
}
