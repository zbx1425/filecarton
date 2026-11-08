# Original demo code, CC0.

Star = Struct.new(:name, :magnitude)

stars = [
  Star.new("Sirius", -1.46),
  Star.new("Vega", 0.03),
]

stars
  .select { |star| star.magnitude < 1 }
  .each { |star| puts format("%-8s %+.2f", star.name, star.magnitude) }
