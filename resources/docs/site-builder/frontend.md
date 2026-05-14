---
title: "Frontend Assets"
description: "Set up Vite (or any build tool) to compile CSS and JavaScript for your Site Builder site."
since: "3.5.0"
---

# Frontend Assets

T3 doesn't own your CSS or JavaScript build pipeline. You choose the tools, write the source files, and output the compiled assets to a public directory. T3's asset functions (`cms.builder.css()`, `cms.builder.js()`, etc.) handle loading them into your templates with automatic cache busting.

This guide covers the recommended setup using **Vite**, with notes on alternatives at the end.

## Quick Start

```bash
# From your project root (same level as tcms-data/)
npm create vite@latest frontend -- --template vanilla
cd frontend
npm install
```

## Directory Structure

A typical T3 builder project looks like this:

```
project/
├── tcms-data/
│   └── builder/
│       ├── layouts/
│       ├── pages/
│       └── partials/
├── public/              ← docroot (web server serves this)
│   ├── assets/          ← compiled output (Vite writes here)
│   │   ├── style.css
│   │   ├── app.js
│   │   └── manifest.json
│   └── index.php        ← T3 entry point
└── frontend/            ← source files (not public)
    ├── src/
    │   ├── css/
    │   │   └── style.css
    │   └── js/
    │       └── app.js
    ├── vite.config.js
    └── package.json
```

Source files live outside the docroot in `frontend/`. Vite compiles them to `public/assets/` where the web server serves them directly.

## Vite Configuration

```js
// frontend/vite.config.js
import { defineConfig } from 'vite'
import { resolve } from 'path'

export default defineConfig({
    build: {
        // Output to the public assets directory
        outDir: resolve(__dirname, '../public/assets'),
        emptyOutDir: true,

        // Generate manifest.json for hashed filenames
        manifest: true,

        rollupOptions: {
            input: {
                style: resolve(__dirname, 'src/css/style.css'),
                app: resolve(__dirname, 'src/js/app.js'),
            },
        },
    },
})
```

### What This Does

- **`outDir`** — writes compiled files to `public/assets/`
- **`manifest: true`** — generates `manifest.json` so T3 can resolve hashed filenames
- **`rollupOptions.input`** — defines your entry points (add as many as needed)

## Package Scripts

```json
{
    "scripts": {
        "dev": "vite",
        "build": "vite build",
        "preview": "vite preview"
    }
}
```

## Development Workflow

### Without Vite Dev Server

The simplest approach — run `npm run build` after making changes:

```bash
cd frontend
npm run build
```

Vite compiles your source files to `public/assets/`. T3's asset functions pick them up automatically via mtime cache busting.

For faster iteration, use Vite's watch mode:

```bash
npm run build -- --watch
```

### With Vite Dev Server

For hot module replacement (HMR) during development, run the Vite dev server and reference its URL directly in your layout during development:

```twig
{# layouts/default.twig #}
{% if cms.env == 'development' %}
    <script type="module" src="http://localhost:5173/src/js/app.js"></script>
{% else %}
    {{ cms.builder.css('style.css') }}
    {{ cms.builder.js('app.js', {module: true}) }}
{% endif %}
```

Start the dev server:

```bash
cd frontend
npm run dev
```

The Vite dev server handles CSS injection via JavaScript in dev mode, so you only need the JS script tag. In production, `cms.builder.css()` and `cms.builder.js()` load the compiled, hashed files from the manifest.

## Production Build

```bash
cd frontend
npm run build
```

This generates hashed filenames and a `manifest.json`:

```
public/assets/
├── style.a1b2c3d4.css
├── app.e5f6a7b8.js
└── manifest.json
```

T3's asset functions read the manifest and output the correct filenames:

```twig
{{ cms.builder.css('src/css/style.css') }}
{# Output: <link rel="stylesheet" href="/assets/style.a1b2c3d4.css"> #}

{{ cms.builder.js('src/js/app.js', {module: true}) }}
{# Output: <script type="module" src="/assets/app.e5f6a7b8.js"></script> #}
```

The path you pass to `css()` and `js()` is the **manifest key** (the input path from your Vite config), not the output filename.

## Using Tailwind CSS

Tailwind works naturally with this setup:

```bash
cd frontend
npm install -D tailwindcss @tailwindcss/vite
```

```js
// frontend/vite.config.js
import { defineConfig } from 'vite'
import { resolve } from 'path'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
    plugins: [tailwindcss()],
    build: {
        outDir: resolve(__dirname, '../public/assets'),
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: {
                style: resolve(__dirname, 'src/css/style.css'),
                app: resolve(__dirname, 'src/js/app.js'),
            },
        },
    },
})
```

```css
/* frontend/src/css/style.css */
@import "tailwindcss";
```

Point Tailwind at your templates so it can scan for classes:

```css
/* frontend/src/css/style.css */
@import "tailwindcss";
@source "../../tcms-data/builder/**/*.twig";
```

## Using Sass/SCSS

```bash
cd frontend
npm install -D sass
```

Vite handles `.scss` files automatically — just change your entry point:

```js
// vite.config.js
rollupOptions: {
    input: {
        style: resolve(__dirname, 'src/css/style.scss'),
        app: resolve(__dirname, 'src/js/app.js'),
    },
},
```

## Layout Template Example

A complete layout using all asset functions:

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}{{ page.title }}{% endblock %}</title>
    <meta name="description" content="{{ page.description }}">

    {{ cms.builder.preload('fonts/inter.woff2', 'font') }}
    {{ cms.builder.css('src/css/style.css') }}
</head>
<body>
    {% include 'partials/nav.twig' %}

    <main>{% block content %}{% endblock %}</main>

    {% include 'partials/footer.twig' %}

    {{ cms.builder.js('src/js/app.js', {module: true}) }}
</body>
</html>
```

## Without a Build Tool

If you don't need a build pipeline, just place plain CSS and JS files directly in `public/assets/`:

```
public/assets/
├── style.css
└── app.js
```

```twig
{{ cms.builder.css('style.css') }}
{{ cms.builder.js('app.js') }}
```

T3 adds `?v={mtime}` query strings for cache busting. No manifest, no build step — just files.

## Alternatives to Vite

### esbuild

```js
// build.js
const esbuild = require('esbuild')

esbuild.build({
    entryPoints: ['src/css/style.css', 'src/js/app.js'],
    outdir: '../public/assets',
    bundle: true,
    minify: true,
})
```

esbuild doesn't generate a Vite-compatible manifest by default. You can use the `esbuild-plugin-manifest` package or rely on mtime cache busting.

### Webpack

Webpack works but is heavier than Vite for this use case. Use `webpack-manifest-plugin` to generate a compatible `manifest.json`.

## See Also

- [Builder Twig Reference](docs/twig/builder) — `css()`, `js()`, `asset()`, `preload()` documentation
- [Site Builder Overview](docs/site-builder/overview)
