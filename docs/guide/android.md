# Android app

The Android app is the collab frontend in Quasar's Capacitor mode. The Android project lives in `src-capacitor/android` (app id `net.toneflix.music`) and loads the same UI as the web app, built into `src-capacitor/www`.

## Building locally

You need the Android SDK (Android Studio installs it) and Java 21.

```bash
yarn install
yarn --cwd src-capacitor install
yarn build:android            # builds the UI and syncs it into src-capacitor/android
cd src-capacitor/android
./gradlew assembleDebug       # app/build/outputs/apk/debug/app-debug.apk
```

`yarn quasar dev -m capacitor -T android` runs the app on a device or emulator with live reload. New Capacitor plugins are added with `yarn --cwd src-capacitor add <plugin>`, then `yarn build:android`. Icons and the splash screen come from `src-icongenie/logo.png` through `yarn icons:app`.

The app talks to the API set by `VITE_PROD_API_BASE_URL`. The API's CORS settings already allow the app's `https://localhost` origin.

## Push notifications

Push reaches the app through Firebase Cloud Messaging and shows in the system tray while the app is closed. Setup:

1. In the Firebase console, add an Android app with package name `net.toneflix.music` to the same Firebase project the API uses, and download its `google-services.json`.
2. On the API, set `FIREBASE_CREDENTIALS` to a service account of that project (see [API .env](../reference/api-env#realtime)). Without it the app can't register for push.
3. Give the build the `google-services.json` (the workflow takes it from the `GOOGLE_SERVICES_JSON` secret; locally, copy it to `src-capacitor/android/app/`).

Each notification category has its own Android channel, so people can mute one kind in system settings. The app asks for notification permission the first time someone signs in, and `/account/notifications` turns push on or off for the phone.

## Publishing to Google Play

`.github/workflows/android.yml` builds a signed app bundle (AAB) and uploads it to Google Play on every push to the `playstore` branch. It can also be started by hand from the Actions tab. Pull requests that touch the app only check that it builds.

### One-time setup

1. **Create an upload key** and keep it safe. Google Play needs every update signed with it.

   ```bash
   keytool -genkeypair -v -keystore upload.keystore -alias upload \
     -keyalg RSA -keysize 2048 -validity 10000
   base64 -w0 upload.keystore   # the value for ANDROID_KEYSTORE_BASE64
   ```

2. **Create the app in the Play Console** with package name `net.toneflix.music`, turn on Play App Signing, and upload the first bundle by hand (Google Play doesn't accept API uploads for an app that has never had a release). The workflow keeps each bundle as a run artifact, so the first one can come from a manual run.
3. **Create a service account for uploads**: in Google Cloud, create a service account with a JSON key and enable the Google Play Android Developer API. In the Play Console, invite the service account's email under *Users and permissions* with permission to release to testing tracks (and production if the workflow should publish there).

### Secrets and variables

Add these under the collab repository's *Settings > Secrets and variables > Actions*.

| Secret | What it holds |
| --- | --- |
| `ANDROID_KEYSTORE_BASE64` | The upload keystore, base64 encoded |
| `ANDROID_KEYSTORE_PASSWORD` | The keystore password |
| `ANDROID_KEY_ALIAS` | The key alias, like `upload` |
| `ANDROID_KEY_PASSWORD` | The key password |
| `GOOGLE_SERVICES_JSON` | The contents of `google-services.json` |
| `PLAY_SERVICE_ACCOUNT_JSON` | The service account's JSON key |

| Variable | Default | What it does |
| --- | --- | --- |
| `PLAY_TRACK` | `internal` | The Play track to upload to: `internal`, `alpha`, `beta` or `production` |
| `PLAY_RELEASE_STATUS` | `completed` | `draft` while the app is still a draft in the Play Console, then `completed` |
| `ANDROID_VERSION_NAME` | `package.json` version | The version people see |
| `ANDROID_VERSION_CODE_OFFSET` | `0` | Added to the run number to give the version code. Raise it if Play already has a higher code. |
| `VITE_*` | `.env` | Override the app's build settings, as for the web deploy |
