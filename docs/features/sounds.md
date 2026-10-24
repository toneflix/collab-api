# Sounds and Freesound

The studio's Sounds panel has two tabs:

- **Library**: a shared set of sounds anyone can drop into a track. Admins fill it, mostly from Freesound.
- **My sounds**: sounds a person uploaded or imported from Freesound. They count against the plan's `max_storage`.

Only CC0 (public domain) sounds are imported from Freesound, so nothing needs attribution.

## Setup

1. Create an API app at [freesound.org/apiv2/apply](https://freesound.org/apiv2/apply).
2. Set its **callback URL** to `<FRONTEND_URL>/freesound/callback`, for example `https://collab.example.com/freesound/callback`.
3. Put its client id and secret in the API's `.env`:

   ```dotenv
   FREESOUND_CLIENT_ID=...
   FREESOUND_CLIENT_SECRET=...
   ```

4. On `/admin/sounds` (needs `manage-configuration`):
   - Turn on **Freesound** to let people search and import into My sounds. They connect their own Freesound account first, because Freesound only gives original files to signed-in users.
   - Turn on **Sound Library** to show the shared library.
   - Pick the **Library Download Account**: an admin whose connected Freesound account downloads files for the library.
   - Add **Library Sync Searches**, one per line. Start a line with a category and a colon, like `Drums: drum loop`.

`sounds:sync` runs every night at 03:30 and adds up to **Sounds per Search** results for each search, skipping sounds longer than **Longest Sound**. It waits `FREESOUND_DOWNLOAD_DELAY` seconds between downloads to stay under Freesound's limits. Run it by hand with `php artisan sounds:sync --force`.
