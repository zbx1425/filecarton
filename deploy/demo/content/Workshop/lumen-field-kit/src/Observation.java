// Original demo code, CC0.
public final class Observation {
    public final String target;
    public final double magnitude;

    public Observation(String target, double magnitude) {
        this.target = target;
        this.magnitude = magnitude;
    }

    public boolean nakedEye() {
        return magnitude < 6.0;
    }

    public static void main(String[] args) {
        Observation vega = new Observation("Vega", 0.03);
        System.out.println(vega.target + " naked eye: " + vega.nakedEye());
    }
}
