# This docs site

The site is built with [VitePress](https://vitepress.dev) from the `docs/` folder of `toneflix/collab-api`.

## Work on it

```bash
cd docs
yarn install
yarn dev        # http://localhost:5173
yarn build      # static site in docs/.vitepress/dist
yarn preview    # serve the build
```

Pages are Markdown. Add new ones to the sidebar in `docs/.vitepress/config.mts`.

## Host it on Cloudflare Pages

The repository is private, so GitHub Pages would need a paid GitHub plan. Instead, the `Docs` workflow (`.github/workflows/docs.yml`) builds the site and deploys it to Cloudflare Pages, which is free.

- Pull requests that change `docs/` only build the site, so broken pages or links fail the check.
- Pushes to `main` that change `docs/` build and deploy it. You can also run it by hand from the Actions tab.

### One-time setup

1. In Cloudflare, create an API token under **My Profile > API Tokens > Create Token > Custom token** with the **Account > Cloudflare Pages > Edit** permission.
2. Copy your **Account ID** from the right side of the Workers & Pages overview.
3. In GitHub, under **Settings > Secrets and variables > Actions**, add the repository secrets `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ACCOUNT_ID`.
4. Optional: add a repository variable `CLOUDFLARE_PAGES_PROJECT` to change the project name. It defaults to `toneflix-collab-docs`, so the site is served at `https://toneflix-collab-docs.pages.dev`.

The first deploy creates the Pages project. Add a custom domain to it in Cloudflare under the project's **Custom domains** tab.

### Keep it private

These pages describe webhooks, admin commands and setup, so you may not want them public. With **Cloudflare Zero Trust > Access**, add a self-hosted application for the Pages domain and allow your team's emails. It is free for up to 50 users. People sign in with a one-time code sent to their email.

### Serving under a sub-path

The site expects to be served from the root of its domain. To serve it under a path, such as `/docs/`, build with `DOCS_BASE=/docs/ yarn build`.
