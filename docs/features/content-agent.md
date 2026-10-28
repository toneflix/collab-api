# Content agent

The content agent turns written instructions into catalogue content: artists, albums of tracks with AI music, and Composer songs. It uses the same tools creators use, so everything it makes is ordinary content on an account.

## Who can use it

The agent builds for **agent accounts**, and for creators whose plan has the **Content agent** feature (`content_agent`, off unless a plan turns it on in Admin → Plans).

### Agent accounts

Admins with `manage-content` add and remove them under **Admin → Content agent → Agent accounts** (`PUT /api/admin/agent/accounts/{user}`), and each change is kept in the account's admin history.

- Everything a run makes belongs to the agent account, and its AI credits pay for it. Give the account a plan with AI tools, enough projects and songs, and credits (Admin → Users).
- Anyone signed in to an agent account sees **Content agent** in the sidebar (`/studio/agent`): the account's runs, and a form to queue new prompts for it.
- Admins see every run and queue prompts for any agent account.

### The plan feature

A creator on a plan with **Content agent** sees it in the studio sidebar too, and runs prompts on their own account with their own AI credits, plan limits and projects. `UserResource` sends `agentAccess` for either case; `contentAgent` stays the agent-account flag.

## A run

1. **Queued.** Each account builds one run at a time, oldest first, and at most five runs (`ContentAgent::MAX_ACTIVE`) build at once across accounts.
2. **Planning.** The Composer language model (`ai_composer_model`) turns the prompt into artists, albums with tracks (a style for the music and a theme for the lyrics, vocals or instrumental) and written-only songs. The plan is capped by the run's "Most tracks" (up to 30) and 10 artists. A run stops here when the account hasn't the credits, projects or songs the plan needs.
3. **Building.** Each album becomes a private project. A track with vocals gets lyrics written into a Composer song, which becomes a project track with its lyric sheet; AI sings it with the Composer music model. An instrumental track uses the studio music model. Each track's preview mix is rendered, then the album is released with all its tracks: as a draft, or published when the run asks for it (release review still applies).
   A track with vocals then has its lyric sheet timed to the sung audio, the same sync the studio's lyrics tool runs (the AI job keeps the written words and takes the times from Whisper), so players show synced lyrics. The release waits for it; a sheet that can't be timed stays untimed.
   With **Artwork** on (the default), AI draws a cover for each album, which goes on its project, release and songs, and a picture for each artist the run creates. When no image comes back (the image model's safety filter blocks some prompts, often dark themes), the agent tries twice more with gentler, abstract prompts (`DrawArtwork::ATTEMPTS`). Artwork that still can't be drawn is skipped, and the work goes on without it.
4. **Done, failed or cancelled.** A failed step stops the run. Retry carries on from where it stopped; steps that finished stay finished.

An existing artist of the account is reused when the plan names it.

### Projects and releases

- An album the instructions ask to keep as a project (or not to release) stops once its tracks are made and mixed. It stays a private studio project with no release.
- The agent can also release the account's **existing projects**. The planner sees the account's latest 50 projects, the projects the prompt names by ID (`#12` or `12`) and up to 50 projects missing artwork, each as `#id "title"` with its track count, release state, whether it's missing artwork and how many tracks have untimed lyrics. The instructions name the project to release by title or ID. Its tracks (up to 30) are mixed, then released through the usual rules: the project owner releases it, an unfinished draft of the project is picked up first, and a published release goes through `ReleaseVersions::published` (frozen audio, release review when it's on). A project released before gets a version label, like "Deluxe", and the earlier live versions are retired when the plan says so.

- **Touch-ups** ("updates" in the plan) fix existing projects named by title or ID, for example "fill in the artwork of #12 and #15" or "sync the lyrics on Old Tapes". Artwork draws one cover and puts it on whatever of the project has no picture: the project, its releases and the Composer songs on its tracks (released tracks show their release's or project's picture). Lyrics time each track's untimed sheet (up to 30 a project) to its audio: the vocal stem when there is one, else the latest. A project with nothing missing is left out. When the same run also releases the project, the release waits for the touch-ups and uses their cover instead of drawing another.
- A **sweep** ("find and fix all missing artwork", or all untimed lyrics) needs no names: the planner sets `sweep.artwork` or `sweep.lyrics`, and the agent finds every project of the account with anything missing a picture (or with untimed lyrics), oldest first, plus albums released without a project that have no cover. Touch-ups named in the prompt and the sweep together are capped at 50 a run (`AgentPlanner::MAX_UPDATES`); run the prompt again for the rest.

## Costs

| Step | Credits |
| --- | --- |
| Plan | one Composer verse ask (`ai_composer_verse_credits`) |
| Lyrics for a song | one Composer verse ask |
| Music | the music model's price for the run's track length |
| Timing a track's lyrics | one lyrics job (`ai_lyrics_credits`) |
| Album cover or artist picture | one artwork request (`ai_artwork_credits`) |

At most three music and artwork jobs run at once per account, the same as in the studio.

## Running it

The scheduler must run `agent:tick` every minute, and a queue worker must be running (runs advance in `AdvanceAgentRun` jobs, and use the AI jobs and mix renders the studio uses).
