declare name "tf_pingpong";
declare tf_label "Ping-pong delay";
declare tf_group "Space";
declare tf_description "Echoes that bounce between left and right.";
import("stdfaust.lib");

time = hslider("time[unit:s]", 0.3, 0.02, 1.5, 0.01) : si.smoo;
feedback = hslider("feedback", 0.5, 0, 0.9, 0.01);
mix = hslider("mix[unit:%]", 0.4, 0, 1, 0.01);

samples = time * ma.SR;
echo(l, r) = loop ~ (_, _) : (!, !, _, _)
with {
    loop(fl, fr) = left, right, left, right
    with {
        left = de.fdelay(96000, samples, (l + r) * 0.5 + fr * feedback);
        right = de.fdelay(96000, samples, fl * feedback);
    };
};
process = _, _ <: (*(1 - mix), *(1 - mix)), (echo : *(mix), *(mix)) :> _, _;
