"""Format a few bright stars. Original demo code, public domain (CC0)."""

from __future__ import annotations

STARS = [
    {"name": "Sirius", "bayer": "α CMa", "magnitude": -1.46, "zh": "天狼"},
    {"name": "Canopus", "bayer": "α Car", "magnitude": -0.74, "zh": "老人"},
    {"name": "Vega", "bayer": "α Lyr", "magnitude": 0.03, "zh": "织女"},
    {"name": "Betelgeuse", "bayer": "α Ori", "magnitude": 0.50, "zh": "参宿四"},
    {"name": "Altair", "bayer": "α Aql", "magnitude": 0.76, "zh": "牛郎"},
    {"name": "Polaris", "bayer": "α UMi", "magnitude": 1.98, "zh": "勾陈一"},
]


def label(star: dict) -> str:
    return f"{star['zh']}  {star['name']:12}  {star['bayer']:7}  V={star['magnitude']:+.2f}"


def brighter_than(limit: float) -> list[dict]:
    return sorted(
        (star for star in STARS if star["magnitude"] < limit),
        key=lambda star: star["magnitude"],
    )


if __name__ == "__main__":
    print("stars brighter than magnitude 1")
    for star in brighter_than(1):
        print(" ", label(star))
