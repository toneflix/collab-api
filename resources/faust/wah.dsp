declare name "tf_wah";
declare tf_label "Auto-wah";
declare tf_group "Movement";
declare tf_description "A funky wah that follows how hard you play.";
import("stdfaust.lib");

amount = hslider("amount[unit:%]", 0.8, 0, 1, 0.01);

process = ve.autowah(amount), ve.autowah(amount);
