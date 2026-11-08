// Toy Kepler helper. Original demo code, CC0.
// Period in days, from the semi-major axis in AU (solar mass = 1).

fn period_days(semi_major_axis_au: f64) -> f64 {
    // P^2 = a^3, with P in years.
    let years = semi_major_axis_au.powf(1.5);
    years * 365.25
}

fn main() {
    let earth = period_days(1.0);
    let mars = period_days(1.524);
    println!("Earth {earth:.1} d");
    println!("Mars  {mars:.1} d");
}
