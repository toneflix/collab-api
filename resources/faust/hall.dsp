declare name "tf_hall";
declare tf_label "Hall reverb";
declare tf_group "Space";
declare tf_description "A lush concert hall (Zita reverb).";
import("stdfaust.lib");

decay = hslider("decay[unit:s]", 3, 0.5, 10, 0.1);
damping = hslider("damping[unit:Hz]", 6000, 1500, 15000, 100);
mix = hslider("mix[unit:%]", 0.3, 0, 1, 0.01);

wet = re.zita_rev1_stereo(60, 200, damping, decay, decay * 0.7, 48000);
process = _, _ <: (*(1 - mix), *(1 - mix)), (wet : *(mix), *(mix)) :> _, _;
