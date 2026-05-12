# Frontend

Vite-based asset pipeline for your Total CMS site. Source files live here; the build emits hashed assets to `../public/assets/`.

## Setup

```bash
npm install
```

## Build

```bash
# One-shot build
npm run build

# Watch mode (rebuild on change)
npm run watch

# Dev server with HMR (advanced — see frontend docs)
npm run dev
```

## How It Connects to T3

In your Twig layout (`tcms-data/builder/layouts/default.twig`):

```twig
{{ cms.builder.css('src/css/style.css') }}
{{ cms.builder.js('src/js/app.js', {module: true}) }}
```

T3 reads `public/assets/manifest.json` and outputs the correct hashed filenames automatically.

## Sass / Tailwind / TypeScript

Vite handles `.scss` natively (just `npm install -D sass`). For Tailwind, install `@tailwindcss/vite` and add the plugin. See the full docs at [docs.totalcms.co/docs/builder/frontend](https://docs.totalcms.co/docs/builder/frontend).
