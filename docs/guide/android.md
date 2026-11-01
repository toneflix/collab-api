# Android app

The Android app is the collab frontend in Quasar's Capacitor mode. The Android project lives in `src-capacitor/android` (app id `com.toneflix.music.app`) and loads the same UI as the web app, built into `src-capacitor/www`.

## Building locally

You need the Android SDK (Android Studio installs it) and Java 21.

```bash
yarn install
yarn --cwd src-capacitor install
yarn build:android            # builds the UI and syncs it into src-capacitor/android
cd src-capacitor/android
./gradlew assembleDebug       # app/build/outputs/apk/debug/app-debug.apk
```

`yarn quasar dev -m capacitor -T android` runs the app on a device or emulator with live reload. New Capacitor plugins are added with `yarn --cwd src-capacitor add <plugin>`, then `yarn build:android`. The launcher icon comes from `src-icongenie/logo-square-filled.png` (from toneflix/assets) through `yarn icons:app`. The splash screens are cut from `src-icongenie/splash.png` with `python3 src-icongenie/splash.py`.

The app talks to the API set by `VITE_PROD_API_BASE_URL`. The API's CORS settings already allow the app's `https://localhost` origin.

## Music playback

Music plays through a native player (Media3, in `PlayerEngine.java` and `PlayerService.java`), not the web page. It keeps playing after the app is closed, and the notification, lock screen and headset controls keep working, since they talk to the native player directly. The player fetches each track's stream link from the API with the signed-in person's token, the same way the app does.

While the app is open it adds recommended tracks as the queue runs out. With the app closed, music stops at the end of the queue (or starts it over with repeat on). Swiping the app away while music is paused clears the notification.

To check it on a phone: play a track, swipe the app away, and use the notification's pause, play, next and previous. Open the app again and it should show the track that's playing.

## Push notifications

Push reaches the app through Firebase Cloud Messaging and shows in the system tray while the app is closed. Setup:

1. In the Firebase console, add an Android app with package name `com.toneflix.music.app` to the same Firebase project the API uses, and download its `google-services.json`.
2. On the API, set `FIREBASE_CREDENTIALS` to a service account of that project (see [API .env](../reference/api-env#realtime)). Without it the app can't register for push.
3. Give the build the `google-services.json` (the workflow takes it from the `GOOGLE_SERVICES_JSON` secret; locally, copy it to `src-capacitor/android/app/`).

Each notification category has its own Android channel, so people can mute one kind in system settings. The app asks for notification permission the first time someone signs in, and `/account/notifications` turns push on or off for the phone.

## App links

Links to `music.toneflix.net` open in the app (Android App Links), so a payment provider sending the payer back reopens the app on the payment return page. Payments still pending when the app was closed are confirmed on the next launch. Another host can be set with `APP_LINK_HOST` at build time.

Android only opens the links in the app once the site proves it owns the app. The web app serves `/.well-known/assetlinks.json` when the Worker build has the `VITE_ANDROID_CERT_FINGERPRINTS` repository variable: the SHA-256 fingerprints of the Play app signing key and the upload key, comma-separated. Both are under **Play Console > Test and release > App integrity > App signing**. After the next web deploy, check it at `https://music.toneflix.net/.well-known/assetlinks.json`, then reinstall the app (Android verifies the links on install).

## Publishing to Google Play

`.github/workflows/android.yml` builds a signed app bundle (AAB) and uploads it to Google Play whenever a GitHub release is published. Pull requests that touch the app only check that it builds.

The release tag sets the version name people see and the Play track. A leading `v` is dropped.

| Tag | Tracks |
| --- | --- |
| `1.1.1-alpha` (or `1.1.1-alpha.2`) | alpha, beta, internal |
| `1.1.1-beta` (or `1.1.1-beta.2`) | beta, internal |
| `1.1.1` | production, alpha, beta, internal |
| `1.1.1` marked *Set as a pre-release*, or any other suffix like `1.1.1-rc1` | internal |

The alpha and beta suffixes pick their tracks even when the release is marked as a pre-release. A tag that is not a version fails the run before anything is built. After the upload, the workflow commits the version and build number to the branch the release was cut from (the default branch when it was cut from a commit).

To build without publishing, start the workflow by hand from the Actions tab (*Android > Run workflow*) and leave **deploy** unticked. The run keeps the signed bundle and APK as an artifact for 30 days; the APK installs directly on a phone. Tick **deploy** to also upload the bundle to Google Play.

The **build** choice picks *production* (the default) or *test*. A test build talks to the staging API (`VITE_STAGING_API_BASE_URL`, or `VITE_API_BASE_URL` when that variable is not set), has debug logging on, gets `-test` after its version name, and only ever goes to the internal track when deployed. A manual production build goes to the `PLAY_TRACK` track. It keeps the same package name, so it replaces the store app on a phone instead of installing beside it.

### Release notes

Every upload fills Play's *What's new* text from the pull requests and direct commits made since the last release commit (`chore: Android release …`, which the workflow commits after each deploy). A production release counts from the last production release, so changes shipped in alpha, beta, internal and test builds show up again.

The notes come from Conventional Commit titles: `feat` goes under *What's new*, `perf` under *Improved* and `fix` under *Fixed*. Other types (`chore`, `ci`, `docs`, `refactor`, `test`) are left out, so write `feat` and `fix` titles the way people using the app should read them. Admin changes are left out too: an `admin` scope (`fix(admin): …`), a title that mentions admins, or a change whose files all have "admin" in their path. A `Release note:` line keeps a change whose title mentions admins. The text stops before Play's 500 character limit and ends with "And more fixes and improvements" when changes were left out. With nothing to list it says "Bug fixes and performance improvements."

To change the wording, add a `Release note:` line to the commit message, such as `Release note: Loop any section of a song in the studio`. `Release note: none` leaves a change out. For a pull request, the line goes in the merge commit's message.

The run summary shows the text that was sent. To preview it locally:

```bash
node .github/scripts/release-notes.mjs whatsnew --skip-prereleases
```

### One-time setup

1. **Create an upload key** and keep it safe. Google Play needs every update signed with it.

   ```bash
   keytool -genkeypair -v -keystore upload.keystore -alias upload \
     -keyalg RSA -keysize 2048 -validity 10000
   ```

   `ANDROID_KEYSTORE_BASE64` holds the keystore (`.keystore` or `.jks`) as one line of base64. Replace `upload.keystore` with the path to your keystore:

   ```bash
   # macOS: copies the value to the clipboard
   base64 -i upload.keystore | pbcopy

   # Linux
   base64 -w0 upload.keystore
   ```

2. **Create the app in the Play Console** with package name `com.toneflix.music.app`, turn on Play App Signing, and upload the first bundle by hand (Google Play doesn't accept API uploads for an app that has never had a release). A manual run without deploy gives you that first bundle.
3. **Create a service account for uploads**: in Google Cloud, create a service account with a JSON key and enable the Google Play Android Developer API. In the Play Console, invite the service account's email under *Users and permissions* with permission to release to testing tracks (and production if the workflow should publish there).

### Secrets and variables

Add these under the collab repository's *Settings > Secrets and variables > Actions*.

| Secret | What it holds |
| --- | --- |
| `ANDROID_KEYSTORE_BASE64` | The upload keystore, base64 encoded |
| `ANDROID_KEYSTORE_PASSWORD` | The keystore password |
| `ANDROID_KEY_ALIAS` | The key alias, like `upload` |
| `ANDROID_KEY_PASSWORD` | The key password. Leave it unset for a PKCS12 keystore, where it is the keystore password |
| `GOOGLE_SERVICES_JSON` | The contents of `google-services.json` |
| `PLAY_SERVICE_ACCOUNT_JSON` | The service account's JSON key |

| Variable | Default | What it does |
| --- | --- | --- |
| `PLAY_TRACK` | `internal` | The Play track for manual production runs: `internal`, `alpha`, `beta` or `production`. Releases take the track from their tag |
| `PLAY_RELEASE_STATUS` | `completed` | `draft` while the app is still a draft in the Play Console, then `completed` |
| `ANDROID_VERSION_CODE_OFFSET` | `0` | Added to the run number to give the version code. Raise it if Play already has a higher code. |
| `VITE_*` | `.env` | Override the app's build settings, as for the web deploy |

#### Using the Firebase admin key for `PLAY_SERVICE_ACCOUNT_JSON`

The Firebase Admin SDK key (`firebase-adminsdk-…@….iam.gserviceaccount.com`) is a normal Google service account key, so it works for Play uploads once:

1. The **Google Play Android Developer API** is enabled in the Google Cloud project that owns it (your Firebase project).
2. Its `client_email` is invited in the Play Console under *Users and permissions* with release permissions for the app.

A separate service account just for Play uploads is still the better choice. The Firebase admin key has wide access to the Firebase project and should not sit in the CI secrets. Create one in the same Cloud project as in step 3 above.
