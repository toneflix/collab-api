declare name "tf_ladder";
declare tf_label "Ladder filter";
declare tf_group "Tone";
declare tf_description "A warm, resonant analog-style low-pass.";
import("stdfaust.lib");

cutoff = hslider("cutoff", 1200, 50, 12000, 1) : si.smoo;
resonance = hslider("resonance", 2, 0.71, 20, 0.01) : si.smoo;

filter = ve.moogLadder(min(cutoff / (ma.SR / 2), 0.99), resonance);
process = filter, filter;
