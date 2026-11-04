# Frontend pipeline reference

> This is a summary. For exhaustive detail, read the on-disk docs that ship with
> the package at `vendor/totalcms/cms/resources/docs/site-builder/` (or query the
> MCP server if connected).

Site Builder templates are server-rendered Twig and need **no** build step. The
frontend pipeline is optional, but the install wizard turns it on by default.

## Install

```bash
vendor/bin/tcms builder:frontend     # or: builder:init <starter> --frontend
```

This installs a Vite project at the project root (`package.json`,
`vite.config.js`, `css/`, `js/`, `FRONTEND.md`), adds the stylesheet to the
default layout, and builds. It is your code — edit it freely. `--no-build` skips
npm, and `--dry-run` shows what would change.

It refuses, and changes nothing, when the project already has a `package.json`
(including a second run), when `public/assets/` holds files a build didn't make
(the build would delete them), or when the project has an older `frontend/`
pipeline. `site-builder/frontend.md` covers each: "Adding Vite to an Existing
Project", "Moving from frontend/", and "Using Vite+" for switching to `vp`.

## Build

```bash
npm install          # first time, and after changing package.json
npm run build        # compiles into public/assets/ (git-ignored build output)
```

During development, `npm run watch` rebuilds on every change. `npm run dev`
starts Vite's dev server for hot reload instead; see `site-builder/frontend.md`
for wiring it into a layout.

## Reference compiled assets in templates

Use the builder helpers. With a build, they look the source path up in
Vite's `manifest.json` and emit the hashed file; without one, they add an
mtime cache-buster to the plain file:

```twig
{{ cms.builder.css('css/style.css') }}
{{ cms.builder.js('js/app.js', {module: true}) }}
{{ cms.builder.asset('img/logo.svg') }}
```

Name the source path (`css/style.css`), not the hashed output. Vite builds ES
modules, so the script tag needs `{module: true}`.

## Notes

- `vite.config.js` is present only if installed; absence is normal for template-only sites.
- With Vite, `public/assets/` is git-ignored build output, wiped on every build. Commit
  `css/`, `js/` and `package-lock.json`. Never add files to `public/assets/` by hand.
- Without Vite, `public/assets/` holds your own files, so commit them. Layouts use the
  same `css/style.css` path either way.
- Keep `publicDir: false`, `base: './'` and `cssTarget: 'esnext'` in `vite.config.js`.
  With `publicDir` on, Vite copies the web root into the build.
- Sites on an older `frontend/` folder keep working: build from there, not the root.
- For deeper config see `vendor/totalcms/cms/resources/docs/site-builder/frontend.md`.
