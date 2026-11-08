-- Original demo code, CC0.

local stars = {
  { name = "Vega", magnitude = 0.03 },
  { name = "Altair", magnitude = 0.76 },
}

table.sort(stars, function(a, b)
  return a.magnitude < b.magnitude
end)

for _, star in ipairs(stars) do
  print(string.format("%s  %.2f", star.name, star.magnitude))
end
