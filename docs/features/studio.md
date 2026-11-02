# Studio (DAW)

Each project track opens in a multitrack editor at `/studio/projects/:project/track/:track`. The project owner and accepted collaborators work on the same stems, and changes show up for everyone in realtime when a [realtime driver](./realtime) is set.

## What it does

- **Stems**: upload audio files as stems, move and trim clips, set fades, volume, pan, mute and solo. The studio mixes the preview in the browser (see [Preview mix](#preview-mix)).
- **Effects** per stem: EQ, reverb, delay, compressor and low-pass filter. They are saved with the stem.
- **Instruments**: a drum machine (kick, snare, clap, hats, tom, rim, shaker) and a piano roll with keys, pluck, pad, bass and lead sounds. Both are synthesized in the browser and uploaded as WAV stems. The pattern is kept, so it can be edited later.
- **Recording** from the microphone into a new stem, uploaded as WAV.
- **Beat grid** with BPM and meter, for snapping and for instruments.
- **Version history**: earlier versions of the track can be restored. How long they are kept is the plan's `history_days`, and `studio:prune-history` deletes older ones.
- **Side panels**: AI tools, history and sounds. On desktop they dock beside the editor, one at a time; on smaller screens they open as a sheet.
- **Region messages**: select part of the timeline and start a conversation about it. Opening the message highlights the region for whoever reads it.

## Preview mix

The studio mixes the preview in the browser, through the same engine and effects it plays with, plugins included. Saving with a preview (or every save, when previews render on save) first saves the arrangement, then renders the whole track offline, turns it down to -0.1 dBFS if it would clip, and uploads it as 16-bit FLAC to `POST creator/projects/{project}/tracks/{track}/mix`. The API encodes it once as a 320 kbps MP3 and keeps it as the track's preview. `STUDIO_MIX_MAX_MB` caps the upload (100 by default, about 20 minutes), and PHP's `upload_max_filesize` and `post_max_size` must allow it too.

Content agent runs, and saves that ask the server for a mix, have no studio open, so `RenderMix` on the queue worker opens the studio in headless Chrome instead. Chrome loads the app's render page (`/studio/render`) with two signed links: one to read the track (`GET studio/render/tracks/{track}`) and one to upload its mix (`POST studio/render/tracks/{track}/mix/{mix}`). The page mixes the track with the same engine, effects and plugins as the studio and uploads it the same way, then sets `window.tfMix` to `done`, `empty` or `failed`, which the job waits for. A mix uploaded for an older request is dropped.

| Variable | Default | |
| --- | --- | --- |
| `STUDIO_CHROME_BINARY` | `chromium` | Chrome or Chromium on the worker |
| `STUDIO_RENDER_URL` | `FRONTEND_URL` + `/studio/render` | The render page |
| `STUDIO_RENDER_TIMEOUT` | `600` | Seconds a render may take |

The track's `mix` field says where the newest mix is (`rendering`, `ready` or `failed`), and a `track.mix` realtime event goes out on each change, so the studio fetches the track again for the new preview link. Only the newest mix is kept. Code: `App\Services\Studio\MixPreview` and `BrowserMix`; in the app, `src/utils/studio/mix.ts` and `src/pages/Studio/RenderMixPage.vue`.

## Project privacy

A project's privacy decides who outside it can see what. The owner and accepted collaborators always have full access.

| Privacy | Project page | Tracks | Studio (DAW) |
| --- | --- | --- | --- |
| `private` | hidden | hidden | closed |
| `public_preview` | anyone | anyone can play each public track's preview mix | closed: stems and assets answer 403 |
| `public` | anyone | anyone can play them | signed-in viewers open it read-only and follow live edits |

`ProjectTrackPolicy::view` covers the track, its lyrics and its conversation; `ProjectTrackPolicy::open` covers stems, assets and the `project-track.{id}` live channel, through `Project::opensStudioTo()`. `ProjectResource` sends `opensStudio` for the viewer. Outsiders see a project's `public` tracks, or all of them when the project sets `tracks_public` ("Make every track public", off by default; agent runs turn it on for Public and Public Preview projects).

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
- The mix is rendered by the studio, in the browser or in headless Chrome on the worker (see [Preview mix](#preview-mix)).
- Effects and instruments are defined in `src/utils/studio/effects.ts` and `src/utils/studio/instruments.ts`.
- Side panels wrap their content in `src/components/Studio/StudioSheet.vue`.
- On the API, stems are `ProjectTrackStem` rows. Their `meta` JSON holds the editor state, such as clips. Versions are in `project_track_versions`.
