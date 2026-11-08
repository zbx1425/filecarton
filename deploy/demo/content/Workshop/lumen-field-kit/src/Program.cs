// Original demo code, CC0.
var stars = new (string Name, double Mag)[]
{
    ("Sirius", -1.46),
    ("Vega", 0.03),
    ("Polaris", 1.98),
};

foreach (var star in stars)
{
    var note = star.Mag < 0 ? "outshines Vega" : "fainter than Sirius";
    Console.WriteLine($"{star.Name,-8} {star.Mag,5:0.00}  {note}");
}
