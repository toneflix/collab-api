---
layout: home

hero:
  name: Toneflix Collab
  text: Setup and feature guides
  tagline: How to run, configure and deploy the Toneflix Collab API and app.
  actions:
    - theme: brand
      text: Get started
      link: /guide/overview
    - theme: alt
      text: .env reference
      link: /reference/api-env

features:
  - title: API
    details: Laravel 13 on PHP 8.3 with PostgreSQL, in toneflix/collab-api.
    link: /guide/api-local
  - title: App
    details: Quasar 3 (Vite) with Vue 3.5, in toneflix/collab. Uses yarn.
    link: /guide/app-local
  - title: Production
    details: Processes to keep running, scheduled tasks, webhooks and post-deploy commands.
    link: /guide/production
---
