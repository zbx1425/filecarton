// Original demo code, CC0.
#include <cmath>
#include <iostream>
#include <string>

struct AltAz {
    double altitude_deg;
    double azimuth_deg;
};

AltAz look_north_at(double altitude_deg) {
    return AltAz{altitude_deg, 0.0};
}

int main() {
    const auto polaris = look_north_at(40.0); // rough altitude at 40 N
    std::cout << "Polaris altitude " << polaris.altitude_deg << " deg\n";
}
