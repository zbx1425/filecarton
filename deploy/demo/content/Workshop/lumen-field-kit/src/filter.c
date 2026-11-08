/* Original demo code, CC0. */
#include "filter.h"
#include <math.h>

double flux_ratio(double magnitude_a, double magnitude_b) {
    return pow(10.0, (magnitude_b - magnitude_a) / 2.5);
}
