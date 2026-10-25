# FFmpeg and Chromaprint

The API uses FFmpeg for two things:

- **Encoding** uploaded tracks and stems (`ProcessAudio` job, settings under `encoding` in `config/settings.php`).
- **Copied-audio detection**. The `FingerprintTrack` job reads [Chromaprint](https://acoustid.org/chromaprint) codes through FFmpeg's `chromaprint` muxer and flags tracks that match another owner's earlier track. See [Moderation](../features/moderation).

Encoding uses `libfdk_aac` unless `AUDIO_ENCODING_CODEC` says otherwise. Fingerprints need an FFmpeg **built with Chromaprint**. Without it, uploads still work and only file hashes are compared, so re-encoded copies are not caught.

## Check your build

```bash
ffmpeg -hide_banner -muxers | grep chromaprint
#  E chromaprint     Chromaprint
```

No output means your FFmpeg has no Chromaprint.

## Install

### Ubuntu and Debian

The distribution package includes Chromaprint, but not `libfdk_aac`, so tell the API to use FFmpeg's own AAC encoder:

```bash
sudo apt-get install ffmpeg
```

```dotenv
AUDIO_ENCODING_CODEC=aac
```

### macOS

Homebrew's core `ffmpeg` formula may not include Chromaprint. The `homebrew-ffmpeg` tap lets you add it:

```bash
brew tap homebrew-ffmpeg/ffmpeg
brew install homebrew-ffmpeg/ffmpeg/ffmpeg --with-chromaprint --with-fdk-aac
```

### Building from source

Pass `--enable-chromaprint` (and install `libchromaprint-dev`). The [ffmpeg-build-script](https://github.com/markus-perl/ffmpeg-build-script) is an easy way to get a static build with `libfdk-aac`.

## Point the API at it

When `ffmpeg` and `ffprobe` are not on the `PATH` of the PHP and queue processes, set their paths:

```dotenv
FFMPEG_BINARIES=/usr/local/bin/ffmpeg
FFPROBE_BINARIES=/usr/local/bin/ffprobe
# Optional, defaults to the system temp folder
FFMPEG_TEMPORARY_FILES_ROOT=/var/tmp/ffmpeg
# Empty uses libfdk_aac. Set aac when your FFmpeg has no libfdk_aac.
AUDIO_ENCODING_CODEC=
```

::: warning The check is cached
The API caches whether Chromaprint is available, forever. After installing or changing FFmpeg, clear it and restart the queue worker:

```bash
php artisan cache:forget audio::chromaprint
php artisan queue:restart
```
:::

## Faust effects

Studio effects written in [Faust](https://faust.grame.fr) live in `resources/faust/*.dsp`. The studio compiles each one in the browser. To render the same effect in the preview mix, the API builds the source into a LADSPA plugin with `faust2ladspa` and runs it through FFmpeg's `ladspa` filter. That needs:

- the `faust` compiler, plus `ladspa-sdk` and `g++` for `faust2ladspa`
- an FFmpeg with the `ladspa` filter (Ubuntu's and Debian's builds have it)

```bash
sudo apt-get install faust ladspa-sdk g++
ffmpeg -hide_banner -filters | grep ladspa
```

Plugins are built the first time a mix uses them and kept in `storage/app/faust`. These variables change the defaults:

```dotenv
FAUST_BINARY=faust
FAUST2LADSPA_BINARY=faust2ladspa
FAUST_PLUGIN_PATH=/var/lib/toneflix/faust
```

Without the compiler, the studio offers no Faust effects, and a mix leaves out any it finds. Each new `.dsp` file needs the `tf_label`, `tf_group` and `tf_description` declarations. Its sliders become the effect's settings.

## Plugins the server can't run

Creators can also add any Web Audio Module (WAM) plugin to a stem by its URL. FFmpeg can't run those, so when the studio saves, it renders the stem through its whole chain in the browser and uploads the result as a WAV (`POST creator/projects/{project}/tracks/{track}/stems/{stem}/print`). The stem keeps its own audio and the plugin's settings, so it can be printed again after any change.

The mix plays the printed audio in place of the stem while at least one plugin in its chain is switched on. Volume, pan, mute and solo still apply on the server. The print is saved under `meta.print` and is left out of copies of the stem.

Prints are 16-bit WAV, about 10 MB a minute. `STUDIO_PRINT_MAX_MB` caps them (100 by default), and PHP's `upload_max_filesize` and `post_max_size` must allow that size too.
