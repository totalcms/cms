---
title: "Frontend Assets"
description: "Set up Vite (or any build tool) to compile CSS and JavaScript for your Site Builder site."
since: "3.5.0"
---

# Frontend Assets

T3 doesn't own your CSS or JavaScript build pipeline. You choose the tools, write the source files, and output the compiled assets to a public directory. T3's asset functions (`cms.builder.css()`, `cms.builder.js()`, etc.) handle loading them into your templates with automatic cache busting.

This guide covers the recommended setup using **Vite**, with notes on alternatives at the end.

## Quick Start

`tcms builder:frontend` installs a Vite pipeline at your project root and builds it. Run it from your project root, the folder that holds `public/`:

```bash
tcms builder:frontend
```

That installs `package.json`, `vite.config.js`, `css/`, `js/` and `FRONTEND.md`, adds the stylesheet to your default layout, then runs `npm install` and the build. From there, edit `css/style.css` and `js/app.js`, and run `npm run build`, or `npm run watch` to rebuild on every change.

The build needs Node 20.19+ or 22.12+. Without it, the files are still installed and the site works without them. Build on any machine with Node, then upload `public/assets/`.

If you want to set up the pipeline yourself, the rest of this guide shows what the scaffold does and why.

## Directory Structure

```
project/
├── tcms-data/
│   └── builder/
│       ├── layouts/
│       ├── pages/
│       └── partials/
├── public/              ← docroot (web server serves this)
│   ├── assets/          ← build output (Vite writes here)
│   │   ├── style-a1b2c3d4.css
│   │   ├── app-e5f6a7b8.js
│   │   └── manifest.json
│   └── index.php        ← T3 entry point
├── css/                 ← source files (not public)
│   └── style.css
├── js/
│   └── app.js
├── vite.config.js
├── package.json
└── FRONTEND.md
```

Source files live outside the docroot. Vite compiles them to `public/assets/`, where the web server serves them directly.

> `public/assets/` is build output, and **every build wipes it**. Don't edit files there or add your own. `builder:frontend` refuses to run if it finds files there that a build didn't make, and lists them, so that nothing is lost.

## Vite Configuration

This is the scaffold's `vite.config.js`:

```js
import { defineConfig } from 'vite'

const NOT_SOURCE = ['vendor', 'cache', 'tmp', 'logs', 'tcms-data', 'builder', 'extensions', 'public']

export default defineConfig({
    base: './',
    publicDir: false,

    server: {
        watch: {
            ignored: NOT_SOURCE.map((dir) => `${import.meta.dirname}/${dir}/**`),
        },
    },

    build: {
        cssTarget: 'esnext',
        outDir: 'public/assets',
        emptyOutDir: true,
        assetsDir: '',
        manifest: 'manifest.json',
        rollupOptions: {
            input: {
                style: 'css/style.css',
                app: 'js/app.js',
            },
        },
    },
})
```

### What This Does

- **`base: './'`** — gives built CSS relative URLs, so it can find its fonts and images
- **`publicDir: false`** — keep this off. Vite's default static folder is `public/`, which is your web root, so Vite would copy `index.php` and `.htaccess` into the build
- **`cssTarget: 'esnext'`** — leaves modern CSS as written. Otherwise Vite rewrites features such as `light-dark()` for older browsers
- **`outDir`** — writes compiled files to `public/assets/`
- **`assetsDir: ''`** — flattens the output, so paths aren't `/assets/assets/style-<hash>.css`
- **`manifest: 'manifest.json'`** — places the manifest where T3 looks first. T3 also checks `.vite/manifest.json`, Vite's default location
- **`rollupOptions.input`** — defines your entry points. Add as many as you need
- **`server.watch.ignored`** — the project root holds far more than source files, so the dev server doesn't watch all of it. Keep these paths tied to the project root. Vite matches them against the full path, so `'**/builder/**'` would also match a site stored inside a folder named `builder`, and nothing would be watched

## Package Scripts

```json
{
    "scripts": {
        "dev": "vite",
        "build": "vite build",
        "watch": "vite build --watch",
        "preview": "vite preview"
    }
}
```

## Development Workflow

### Without Vite Dev Server

The simplest approach is to build after making changes:

```bash
npm run build
```

Vite compiles your source files to `public/assets/`, and T3's asset functions read the new filenames from the manifest.

To rebuild on every change:

```bash
npm run watch
```

### With Vite Dev Server

For hot module replacement (HMR) during development, run the Vite dev server and reference its URL directly in your layout during development:

```twig
{# layouts/default.twig #}
{% if cms.env == 'development' %}
    <script type="module" src="http://localhost:5173/js/app.js"></script>
{% else %}
    {{ cms.builder.css('css/style.css') }}
    {{ cms.builder.js('js/app.js', {module: true}) }}
{% endif %}
```

Start the dev server:

```bash
npm run dev
```

The Vite dev server handles CSS injection via JavaScript in dev mode, so you only need the JS script tag. In production, `cms.builder.css()` and `cms.builder.js()` load the compiled, hashed files from the manifest.

## Production Build

```bash
npm run build
```

This generates hashed filenames and a `manifest.json`:

```
public/assets/
├── style-a1b2c3d4.css
├── app-e5f6a7b8.js
└── manifest.json
```

T3's asset functions read the manifest and output the correct filenames:

```twig
{{ cms.builder.css('css/style.css') }}
{# Output: <link rel="stylesheet" href="/assets/style-a1b2c3d4.css"> #}

{{ cms.builder.js('js/app.js', {module: true}) }}
{# Output: <script type="module" src="/assets/app-e5f6a7b8.js"></script> #}
```

The path you pass to `css()` and `js()` is the **manifest key** (the input path from your Vite config), not the output filename.

Commit `package-lock.json`, so that deploys install the same versions. `bin/deploy.sh` builds the site on the server, so the server needs Node. If yours doesn't have it, build locally and upload `public/assets/`.

## Adding a CSS Framework or Package

Install the package, then import it at the top of `css/style.css`:

```bash
npm install some-package
```

```css
@import "some-package";
```

## Fonts and Images

Keep them in their own folder, such as `fonts/`, and use them from your CSS. Vite finds each `url()` and copies the file into the build with a hashed name, so there's nothing to configure:

```css
@font-face {
    font-family: "Inter";
    src: url('../fonts/inter.woff2');
}
```

Files under 4 KB are embedded in the CSS instead, so they don't appear in `public/assets/`.

Files that CSS doesn't reference are not copied. For those, such as images used in templates, set `publicDir` to a folder such as `'static'`. Vite copies that folder into the build as it is, without hashing the filenames. Never set it to `'public'`.

## Using Tailwind CSS

Tailwind works naturally with this setup:

```bash
npm install -D tailwindcss @tailwindcss/vite
```

Add the plugin to `vite.config.js`, and keep everything else:

```js
import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
    plugins: [tailwindcss()],
    // ...the rest of the scaffold's config
})
```

Point Tailwind at your templates so it can scan for classes:

```css
/* css/style.css */
@import "tailwindcss";
@source "../tcms-data/builder/**/*.twig";
```

For git-managed templates, use `@source "../builder/**/*.twig";`.

Tailwind only sees a template edit when it rebuilds your CSS, and neither Vite mode does that on its own:

- **`npm run dev`** — templates aren't watched, so the browser doesn't update. Refresh the page and the new classes are there.
- **`npm run watch`** — a template edit doesn't trigger a rebuild. Run `npm run build` to pick it up.

## Using Sass/SCSS

```bash
npm install -D sass
```

Vite handles `.scss` files automatically. Change your entry point:

```js
// vite.config.js
rollupOptions: {
    input: {
        style: 'css/style.scss',
        app: 'js/app.js',
    },
},
```

The manifest key changes too, so load it with `{{ cms.builder.css('css/style.scss') }}`.

## Adding Vite to an Existing Project

`tcms builder:frontend` only installs into a project with no `package.json`, so it never edits a setup you already have. If you have your own `package.json`, add Vite by hand:

1. Install Vite and copy the scaffold's config. If your `package.json` isn't `"type": "module"`, name the copy `vite.config.mjs`.

   ```bash
   npm install -D vite
   cp vendor/totalcms/cms/resources/builder/frontend/vite.config.js .
   ```

2. Add a `build` script to `package.json`: `"build": "vite build"`.
3. Create `css/style.css` and `js/app.js`, or change `rollupOptions.input` to match your files.
4. Add `/node_modules/` and `/public/assets/` to `.gitignore`.
5. Move any files out of `public/assets/`, because the first build wipes that folder.
6. Add `{{ cms.builder.css('css/style.css') }}` to your layout, then run `npm run build`.

## Using Vite+

The scaffold uses plain Vite. To use [Vite+](https://viteplus.dev) instead, replace the package:

```bash
npm uninstall vite
npm install -D vite-plus
```

In `vite.config.js`, import from `'vite-plus'` instead of `'vite'`, and keep the rest of the config. In `package.json`, change the scripts to use `vp`:

```json
{
    "scripts": {
        "dev": "vp dev",
        "build": "vp build",
        "watch": "vp build --watch",
        "preview": "vp preview"
    }
}
```

The build output and the manifest are the same, so your layouts don't change, and `bin/deploy.sh` still works. Check which Node version Vite+ needs, because it can be newer than the version Vite needs. Your server needs it too.

## Moving from frontend/

Older sites keep the pipeline in a `frontend/` folder. That still works, and it still deploys, so you only need to move if you want the new layout. `tcms builder:frontend` won't run on a project that has a `frontend/` pipeline. Move it by hand, from your project root:

1. Move your source and package files to the root, including any other folders you added, such as `fonts/`:

   ```bash
   mv frontend/css frontend/js frontend/package.json frontend/package-lock.json .
   ```

2. Copy the new config and guide. If your `package.json` isn't `"type": "module"`, name the config `vite.config.mjs`.

   ```bash
   cp vendor/totalcms/cms/resources/builder/frontend/vite.config.js .
   cp vendor/totalcms/cms/resources/builder/frontend/FRONTEND.md .
   ```

3. Add any changes you made in `frontend/vite.config.js` to the new config, such as plugins or extra entry points, then delete the old folder:

   ```bash
   rm -rf frontend
   ```

4. Install and build:

   ```bash
   npm install
   npm run build
   ```

5. In `.gitignore`, replace `/frontend/node_modules/` with `/node_modules/`, and check that `/public/assets/` is listed.

6. Update `bin/deploy.sh`. A copy made before this change only builds `frontend/`, so replace its `if [ -d frontend ]` block with one that runs `npm ci` and `npm run build` from the project root.

Your layouts don't change. They name the same source paths, such as `css/style.css`.

## Core Total CMS Assets

Before your own assets, your layout needs the ones Total CMS ships. `cms.assetsHead()` and `cms.assetsBody()` emit the core stylesheets and scripts that T3's own Twig output depends on — grid layout, galleries, pagination, icons, htmx, the form runtime behind `cms.form.*`, and the decoder behind the [`mailto`](docs/twig/filters) filter — plus anything registered by [extensions](docs/extensions/extension-points).

```twig
{{ cms.assetsHead() }}   {# in <head>, before your own CSS #}
{{ cms.assetsBody() }}   {# just before </body> #}
```

These are separate from the `cms.builder.*` functions below. `cms.builder.css()` and `cms.builder.js()` load **your** files out of the builder assets directory; `cms.assetsHead()` / `cms.assetsBody()` load **Total CMS's**. A layout wants both.

Leave them out and nothing errors — `{% cmsgrid %}` renders unstyled, galleries don't lay out, Load More doesn't fetch, and obfuscated email addresses never become links. See [Frontend Assets](docs/twig/overview) in the Twig overview for the full symptom table.

Put `cms.assetsHead()` before your own stylesheet so your rules override the defaults.

### Leaving out what you do not use

The core set covers every feature Total CMS can render, but a given site rarely uses all of it. A marketing site with no `{% cmsgrid %}`, no rendered galleries, no pagination and no CMS icons still loads their stylesheets on every page. Name the features you never render in `tcms.php` and the helpers skip their files:

```php
$settings['frontendAssets'] = [
	'except' => ['icons', 'cms-grid', 'gallery', 'pagination', 'htmx'],
];
```

Names are features, not filenames: `gallery` drops both `gallery.css` and `gallery.js` (and the script's preload hint), so you never ship half a pair. The names are `icons`, `content`, `cms-grid`, `gallery`, `pagination`, `forms` and `htmx`. Keep `content` — it carries the styled-text styles and the decoder behind the `mailto` filter. `forms` is the stylesheet and script behind every `cms.form.*` form (see [What a public form needs](docs/forms/overview#what-a-public-form-needs)); leave it out only on a site with no public forms. Extension assets are never affected, and a name that does not exist is ignored. This is an exclude list on purpose: enable an extension with frontend assets, or upgrade to a version that adds a core asset, and it still arrives.

The same names work per call, for a Stacks site where the layout is written page by page and there is no config file to edit:

```twig
{{ cms.assetsHead({except: ['icons', 'gallery', 'htmx']}) }}
…
{{ cms.assetsBody({except: ['icons', 'gallery', 'htmx']}) }}
```

A call's `except` adds to the site setting. Give `assetsHead()` and `assetsBody()` the same list — a feature can be a stylesheet in the head and a script in the body, and the two calls do not know about each other.

## Layout Template Example

A complete layout using all asset functions:

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}{{ page.title }}{% endblock %}</title>
    <meta name="description" content="{{ page.seo.description }}">

    {# Total CMS core assets first, so your own CSS can override them #}
    {{ cms.assetsHead() }}

    {{ cms.builder.preload('fonts/inter.woff2', 'font') }}
    {{ cms.builder.css('css/style.css') }}
</head>
<body>
    {% include 'partials/nav.twig' %}

    <main>{% block content %}{% endblock %}</main>

    {% include 'partials/footer.twig' %}

    {{ cms.assetsBody() }}
    {{ cms.builder.js('js/app.js', {module: true}) }}
</body>
</html>
```

## Without a Build Tool

If you don't need a build pipeline, just place plain CSS and JS files directly in `public/assets/`, and commit them:

```
public/assets/
├── css/
│   └── style.css
└── js/
    └── app.js
```

```twig
{{ cms.assetsHead() }}
{{ cms.builder.css('css/style.css') }}

{{ cms.assetsBody() }}
{{ cms.builder.js('js/app.js') }}
```

T3 adds `?v={mtime}` query strings for cache busting. No manifest, no build step — just files. The core asset helpers are still required with or without a build tool.

These are the same paths the Vite pipeline uses, so a layout doesn't change if you add it later. Move the files to `css/` and `js/` at the project root first, because the first build wipes `public/assets/`.

## Alternatives to Vite

### esbuild

```js
// build.js
const esbuild = require('esbuild')

esbuild.build({
    entryPoints: ['css/style.css', 'js/app.js'],
    outdir: 'public/assets',
    bundle: true,
    minify: true,
})
```

esbuild doesn't generate a Vite-compatible manifest by default. You can use the `esbuild-plugin-manifest` package or rely on mtime cache busting.

### Webpack

Webpack works but is heavier than Vite for this use case. Use `webpack-manifest-plugin` to generate a compatible `manifest.json`.

## See Also

- [Builder Twig Reference](docs/site-builder/twig) — `css()`, `js()`, `asset()`, `preload()` documentation
- [Site Builder Overview](docs/site-builder/overview)
