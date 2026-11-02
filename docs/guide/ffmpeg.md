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

Studio effects written in [Faust](https://faust.grame.fr) live in `resources/faust/*.dsp`. The studio compiles and runs each one in the browser, mixes included. The API only reads each effect's settings with the `faust` compiler:

```bash
sudo apt-get install faust
```

`FAUST_BINARY` points at another compiler (`faust` by default). Without it, the studio offers no Faust effects. Each new `.dsp` file needs the `tf_label`, `tf_group` and `tf_description` declarations. Its sliders become the effect's settings.

FFmpeg no longer mixes tracks: every preview mix, content agent runs included, is made by the studio. See [Preview mix](../features/studio#preview-mix).
