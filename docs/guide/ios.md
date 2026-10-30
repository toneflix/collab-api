# iOS app

The iOS app is the collab frontend in Quasar's Capacitor mode, like the [Android app](./android). The Xcode project lives in `src-capacitor/ios/App` (bundle id `com.toneflix.music.app`) and loads the same UI as the web app, built into `src-capacitor/www`. It needs iOS 15 or later.

## Building locally

You need a Mac with Xcode, and an Apple Developer account to run the app on a phone.

```bash
yarn install
yarn --cwd src-capacitor install
yarn build:ios                # builds the UI and syncs it into src-capacitor/ios
open src-capacitor/ios/App/App.xcodeproj
```

In Xcode, pick the **App** scheme and a phone, then Run. `yarn dev:ios` runs the app with live reload.

The first time, open the **App** target's *Signing & Capabilities* tab, choose your team under *Signing*, and check that **Push Notifications** and **Background Modes** (*Audio* and *Remote notifications*) are listed. Xcode registers the bundle id with Apple when you pick a team.

Capacitor plugins come in through Swift Package Manager. New ones are added with `yarn --cwd src-capacitor add <plugin>`, then `yarn build:ios`. Don't edit `src-capacitor/ios/App/CapApp-SPM`: the Capacitor CLI rewrites it on every sync. Firebase is added to the Xcode project itself, so it stays put.

The launcher icon comes from `src-icongenie/logo-square-filled.png` through `yarn icons:app`, which makes the Android and iOS icons together. The splash screen is `src-icongenie/splash.png`, copied into `App/Assets.xcassets/Splash.imageset`. Copy it again when the splash changes.

## Music playback

Music plays through a native player (`MusicPlayer.swift`), not the web page. It keeps playing while the app is in the background or the phone is locked, moves on to the next track by itself, pauses for calls and resumes after them, and shows on the lock screen and Control Center. It fetches each track's stream link from the API with the signed-in person's token, the same way the app does.

Swiping the app away quits it, and the music stops with it. Every iOS music app works that way.

## Push notifications

Push goes through Firebase Cloud Messaging, the same as Android, so the API needs no extra setup beyond `FIREBASE_CREDENTIALS`. Apple delivers the message, so Firebase needs a key from Apple. Setup:

1. **Create an APNs key.** In the Apple Developer portal, under *Certificates, Identifiers & Profiles > Keys*, create a key with **Apple Push Notifications service (APNs)** ticked. Download the `.p8` file (Apple lets you download it only once) and note the Key ID and your Team ID.
2. **Add the iOS app to Firebase.** In the Firebase console, open the same project the API uses and add an iOS app with bundle id `com.toneflix.music.app`. Download its `GoogleService-Info.plist`.
3. **Give Firebase the APNs key.** In *Project settings > Cloud Messaging > Apple app configuration*, upload the `.p8` key with its Key ID and Team ID.
4. **Add the plist to the app.** Drag `GoogleService-Info.plist` into Xcode under `App/App`, with the **App** target ticked.

Without the plist the app builds and runs, and push stays off: the app checks for it before registering. The app asks for notification permission the first time someone signs in, and `/account/notifications` turns push on or off for the phone, as on Android.

`App/App.entitlements` sets `aps-environment` to `development`. Xcode switches it to `production` when it exports a build for TestFlight or the App Store.

## Uploads

iOS gives an app about 30 more seconds after it leaves the screen, and the app uses them to finish uploads in progress. An upload that takes longer stops when iOS suspends the app. Ask people to keep the app open while big files upload.

## Universal links

`App.entitlements` lists `applinks:music.toneflix.net`, so the site's links open in the app. The App ID needs the **Associated Domains** capability in the Apple developer portal. The web app serves `/.well-known/apple-app-site-association` when the Worker build has the `VITE_IOS_TEAM_ID` repository variable (the 10-character Apple team id).

## Publishing to the App Store

1. **Create the app** in App Store Connect with bundle id `com.toneflix.music.app`.
2. **Fill in App Privacy.** Declare what the app collects, such as name, email, user content (uploads, messages) and purchases, linked to the account and not used for tracking. `App/PrivacyInfo.xcprivacy` already says the app doesn't track.
3. **Archive and upload.** In Xcode, choose *Any iOS Device*, then *Product > Archive*, and upload from the Organizer. The build shows up in TestFlight for testing, and you submit it for review from App Store Connect.
4. **Set the version.** Change *Version* and *Build* on the App target's *General* tab before each upload. Every upload needs a higher build number.

::: warning Payments
Releases, stems and tips are paid for through Paystack inside the app. Apple usually requires its own in-app purchases for digital goods like these, so App Review is likely to turn the app down while paying is available on iOS. Decide how iOS handles payments before submitting: hide them there, add Apple in-app purchases, or link out to the web where Apple allows it.
:::

## Testing on a phone

These need a real iPhone; the simulator doesn't run background audio or push the same way.

- Play a track, lock the phone, and let it finish: the next track should start.
- Take a phone call while music plays: it should pause, then carry on when the call ends.
- Use the lock screen's play, pause, next, previous and seek controls.
- Pick a paid track you don't own: the app should say it's part of a paid release and not play it.
- Turn push on, close the app, and trigger a notification (for example, send a message from another account).
