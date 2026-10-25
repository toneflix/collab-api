declare name "tf_pitch";
declare tf_label "Pitch shift";
declare tf_group "Colour";
declare tf_description "Raise or lower the pitch without changing the timing.";
import("stdfaust.lib");

shift = hslider("shift", 12, -12, 12, 0.1);
mix = hslider("mix[unit:%]", 1, 0, 1, 0.01);

shifted = ef.transpose(2048, 512, shift);
voice = _ <: *(1 - mix), (shifted : *(mix)) :> _;
process = voice, voice;
