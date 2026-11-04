# Frontend

This site builds its CSS and JavaScript with [Vite](https://vite.dev).

| Path | What it is |
|---|---|
| `css/`, `js/` | Your source files. Edit these. |
| `public/assets/` | Build output. Don't edit it, and don't put files there: every build wipes it. |

## Commands

Run these from the project root.

```bash
npm install       # first time, and after changing package.json
npm run build     # build once
npm run watch     # rebuild on every change
```

Commit `package-lock.json` so that deploys install the same versions. `bin/deploy.sh`
builds the site on the server, so the server needs Node. If yours doesn't have it, build
locally and upload `public/assets/`.

## Using the files in a template

Name the source file, and Total CMS finds the built one:

```twig
{{ cms.builder.css('css/style.css') }}
{{ cms.builder.js('js/app.js', {module: true}) }}
```

The default layout already loads the stylesheet. Add the second line when you write JavaScript.

## Adding a CSS framework or package

Install it, then import it at the top of `css/style.css`:

```bash
npm install some-package
```

```css
@import "some-package";
```

## Adding entry points

Add a line to `rollupOptions.input` in `vite.config.js`. For Sass, run `npm install -D sass`.
For more, see the [frontend docs](https://docs.totalcms.co/site-builder/frontend/).
