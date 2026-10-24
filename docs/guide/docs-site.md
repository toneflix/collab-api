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

The repository is private, so GitHub Pages would need a paid GitHub plan. Cloudflare Pages builds from private repositories for free.

1. In the Cloudflare dashboard, go to **Workers & Pages > Create > Pages > Connect to Git** and pick `toneflix/collab-api`.
2. Use these build settings:

   | Setting | Value |
   | --- | --- |
   | Production branch | `main` |
   | Framework preset | VitePress |
   | Root directory | `docs` |
   | Build command | `yarn build` |
   | Build output directory | `.vitepress/dist` |
   | Environment variable | `NODE_VERSION` = `22` |

3. Save. Every push to `main` publishes the site, and pull requests get preview links.

To build only when the docs change, set **Build watch paths** to `docs/*`.

### Keep it private

These pages describe webhooks, admin commands and setup, so you may not want them public. With **Cloudflare Zero Trust > Access**, add a self-hosted application for the Pages domain and allow your team's emails. It is free for up to 50 users. People sign in with a one-time code sent to their email.

### Serving under a sub-path

The site expects to be served from the root of its domain. To serve it under a path, such as `/docs/`, build with `DOCS_BASE=/docs/ yarn build`.
