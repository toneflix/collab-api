# Content agent

The content agent turns written instructions into catalogue content: artists, albums of tracks with AI music, and Composer songs. It uses the same tools creators use, so everything it makes is ordinary content on an account.

## Agent accounts

The agent only builds for **agent accounts**. Admins with `manage-content` add and remove them under **Admin → Content agent → Agent accounts** (`PUT /api/admin/agent/accounts/{user}`), and each change is kept in the account's admin history.

- Everything a run makes belongs to the agent account, and its AI credits pay for it. Give the account a plan with AI tools, enough projects and songs, and credits (Admin → Users).
- Anyone signed in to an agent account sees **Content agent** in the sidebar (`/studio/agent`): the account's runs, and a form to queue new prompts for it.
- Admins see every run and queue prompts for any agent account.

## A run

1. **Queued.** Runs go one at a time, oldest first.
2. **Planning.** The Composer language model (`ai_composer_model`) turns the prompt into artists, albums with tracks (a style for the music and a theme for the lyrics, vocals or instrumental) and written-only songs. The plan is capped by the run's "Most tracks" (up to 30) and 10 artists. A run stops here when the account hasn't the credits, projects or songs the plan needs.
3. **Building.** Each album becomes a private project. A track with vocals gets lyrics written into a Composer song, which becomes a project track with its lyric sheet; AI sings it with the Composer music model. An instrumental track uses the studio music model. Each track's preview mix is rendered, then the album is released with all its tracks: as a draft, or published when the run asks for it (release review still applies).
   With **Artwork** on (the default), AI draws a cover for each album, which goes on its project, release and songs, and a picture for each artist the run creates. Artwork that can't be drawn is skipped, and the work goes on without it.
4. **Done, failed or cancelled.** A failed step stops the run. Retry carries on from where it stopped; steps that finished stay finished.

An existing artist of the account is reused when the plan names it.

## Costs

| Step | Credits |
| --- | --- |
| Plan | one Composer verse ask (`ai_composer_verse_credits`) |
| Lyrics for a song | one Composer verse ask |
| Music | the music model's price for the run's track length |
| Album cover or artist picture | one artwork request (`ai_artwork_credits`) |

At most three music and artwork jobs run at once per account, the same as in the studio.

## Running it

The scheduler must run `agent:tick` every minute, and a queue worker must be running (runs advance in `AdvanceAgentRun` jobs, and use the AI jobs and mix renders the studio uses).
