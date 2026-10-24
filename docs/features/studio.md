# Studio (DAW)

Each project track opens in a multitrack editor at `/studio/projects/:project/track/:track`. The project owner and accepted collaborators work on the same stems, and changes show up for everyone in realtime when a [realtime driver](./realtime) is set.

## What it does

- **Stems**: upload audio files as stems, move and trim clips, set fades, volume, pan, mute and solo. A preview mix is rendered in the browser and saved as MP3.
- **Effects** per stem: EQ, reverb, delay, compressor and low-pass filter. They are saved with the stem.
- **Instruments**: a drum machine (kick, snare, clap, hats, tom, rim, shaker) and a piano roll with keys, pluck, pad, bass and lead sounds. Both are synthesized in the browser and saved as ordinary stems. The pattern is kept, so it can be edited later.
- **Recording** from the microphone into a new stem.
- **Beat grid** with BPM and meter, for snapping and for instruments.
- **Version history**: earlier versions of the track can be restored. How long they are kept is the plan's `history_days`, and `studio:prune-history` deletes older ones.
- **Side panels**: AI tools, history and sounds. On desktop they dock beside the editor, one at a time; on smaller screens they open as a sheet.
- **Region messages**: select part of the timeline and start a conversation about it. Opening the message highlights the region for whoever reads it.

## Limits from the plan

| Feature | Effect |
| --- | --- |
| `max_projects` | Projects someone can create |
| `max_collaborators` | Collaborators per project |
| `max_stem_size` | Largest stem upload |
| `max_storage` | Space for their own sounds |
| `history_days` | How long versions are kept |

## In code

- `src/components/Studio/StemEditor.vue` wraps [`@dawcore/components`](https://www.npmjs.com/package/@dawcore/components) (pinned to 0.0.37). The editor is created in `onMounted`, so it is SSR safe.
- Clip fades and the exported mix are handled by StemEditor itself with an `OfflineAudioContext`, because dawcore does not apply them.
- Effects and instruments are defined in `src/utils/studio/effects.ts` and `src/utils/studio/instruments.ts`.
- Side panels wrap their content in `src/components/Studio/StudioSheet.vue`.
- On the API, stems are `ProjectTrackStem` rows. Their `meta` JSON holds the editor state, such as clips. Versions are in `project_track_versions`.
