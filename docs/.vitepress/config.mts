import { defineConfig } from 'vitepress';

export default defineConfig({
  title: 'Toneflix Music',
  description: 'Setup and feature guides for the Toneflix Music API and app.',
  // Set DOCS_BASE when the site is served from a sub-path, like /docs/.
  base: process.env.DOCS_BASE || '/',
  cleanUrls: true,
  lastUpdated: true,

  themeConfig: {
    nav: [
      { text: 'Setup', link: '/guide/overview' },
      { text: 'Reference', link: '/reference/api-env' },
      { text: 'Features', link: '/features/plans' },
    ],

    sidebar: [
      {
        text: 'Setup',
        items: [
          { text: 'Overview', link: '/guide/overview' },
          { text: 'API: local setup', link: '/guide/api-local' },
          { text: 'App: local setup', link: '/guide/app-local' },
          { text: 'FFmpeg and Chromaprint', link: '/guide/ffmpeg' },
          { text: 'Production deploy', link: '/guide/production' },
          { text: 'Railpack and Railway', link: '/guide/railpack' },
          { text: 'Android app', link: '/guide/android' },
          { text: 'iOS app', link: '/guide/ios' },
          { text: 'Payment providers', link: '/guide/payments' },
          { text: 'This docs site', link: '/guide/docs-site' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'API .env', link: '/reference/api-env' },
          { text: 'App .env', link: '/reference/app-env' },
          { text: 'App configuration', link: '/reference/configuration' },
          { text: 'Commands and schedule', link: '/reference/commands' },
          { text: 'Roles and permissions', link: '/reference/permissions' },
        ],
      },
      {
        text: 'Features',
        items: [
          { text: 'Plans and features', link: '/features/plans' },
          { text: 'Users', link: '/features/users' },
          { text: 'Sign-in and verification', link: '/features/auth' },
          { text: 'AI tools and credits', link: '/features/ai' },
          { text: 'Studio (DAW)', link: '/features/studio' },
          { text: 'Sounds and Freesound', link: '/features/sounds' },
          { text: 'Composer', link: '/features/composer' },
          { text: 'Content agent', link: '/features/content-agent' },
          { text: 'Admin assistant', link: '/features/admin-assistant' },
          { text: 'Realtime', link: '/features/realtime' },
          { text: 'Conversations', link: '/features/conversations' },
          { text: 'Notifications', link: '/features/notifications' },
          { text: 'Payments', link: '/features/payments' },
          { text: 'Payouts', link: '/features/payouts' },
          { text: 'Stream earnings', link: '/features/streams' },
          { text: 'Invites', link: '/features/invites' },
          { text: 'Moderation', link: '/features/moderation' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/toneflix/collab-api' },
    ],

    editLink: {
      pattern: 'https://github.com/toneflix/collab-api/edit/main/docs/:path',
    },

    search: { provider: 'local' },

    outline: [2, 3],
  },
});
