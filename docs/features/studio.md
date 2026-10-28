# Studio (DAW)

Each project track opens in a multitrack editor at `/studio/projects/:project/track/:track`. The project owner and accepted collaborators work on the same stems, and changes show up for everyone in realtime when a [realtime driver](./realtime) is set.

## What it does

- **Stems**: upload audio files as stems, move and trim clips, set fades, volume, pan, mute and solo. The preview mix is rendered on the server with FFmpeg (see [Preview mix](#preview-mix)).
- **Effects** per stem: EQ, reverb, delay, compressor and low-pass filter. They are saved with the stem.
- **Instruments**: a drum machine (kick, snare, clap, hats, tom, rim, shaker) and a piano roll with keys, pluck, pad, bass and lead sounds. Both are synthesized in the browser and uploaded as WAV stems. The pattern is kept, so it can be edited later.
- **Recording** from the microphone into a new stem, uploaded as WAV.
- **Beat grid** with BPM and meter, for snapping and for instruments.
- **Version history**: earlier versions of the track can be restored. How long they are kept is the plan's `history_days`, and `studio:prune-history` deletes older ones.
- **Side panels**: AI tools, history and sounds. On desktop they dock beside the editor, one at a time; on smaller screens they open as a sheet.
- **Region messages**: select part of the timeline and start a conversation about it. Opening the message highlights the region for whoever reads it.

## Preview mix

The browser never encodes the mix. Saving with a preview (or every save, when previews render on save) sends `render: true` and the master volume as `mix_volume` (0 to 1) with the arrangement. The API queues `RenderMix`, which:

1. Reads each stem's saved `meta`: clips (start, offset, length, gain, linear fades), volume, pan, mute, solo and effects.
2. Builds one FFmpeg filter graph at 44.1 kHz. Effects use the filters closest to their Web Audio versions: shelves and a peak filter for the EQ, `afir` with the same noise-burst room for reverb, `aecho` taps for delay, `acompressor` with peak detection and Web Audio's makeup gain, and a two pole `lowpass`. Panning is equal power, like `StereoPannerNode`.
3. Mixes to a 32 bit float WAV, turns it down to -0.1 dBFS if it would clip, and encodes it once as a 320 kbps MP3 preview.

The track's `mix` field says where the newest render is (`rendering`, `ready` or `failed`), and a `track.mix` realtime event goes out on each change, so the studio fetches the track again for the new preview link. Only the newest render request is kept. Code: `App\Services\Studio\MixPreview`, `Mix\MixRenderer`, `Mix\MixStem` and `Mix\Effects\*`.

## Project privacy

A project's privacy decides who outside it can see what. The owner and accepted collaborators always have full access.

| Privacy | Project page | Tracks | Studio (DAW) |
| --- | --- | --- | --- |
| `private` | hidden | hidden | closed |
| `public_preview` | anyone | anyone can play each public track's preview mix | closed: stems and assets answer 403 |
| `public` | anyone | anyone can play them | signed-in viewers open it read-only and follow live edits |

`ProjectTrackPolicy::view` covers the track, its lyrics and its conversation; `ProjectTrackPolicy::open` covers stems, assets and the `project-track.{id}` live channel, through `Project::opensStudioTo()`. `ProjectResource` sends `opensStudio` for the viewer. Tracks follow their project's privacy; the per-track `privacy` column no longer hides a track.

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
- Clip fades are set on the engine by StemEditor, because dawcore does not load them. The mix itself is rendered by the API (see [Preview mix](#preview-mix)).
- Effects and instruments are defined in `src/utils/studio/effects.ts` and `src/utils/studio/instruments.ts`.
- Side panels wrap their content in `src/components/Studio/StudioSheet.vue`.
- On the API, stems are `ProjectTrackStem` rows. Their `meta` JSON holds the editor state, such as clips. Versions are in `project_track_versions`.
