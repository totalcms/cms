# Frontend pipeline reference

> This is a summary. For exhaustive detail, read the on-disk docs that ship with
> the package at `vendor/totalcms/cms/resources/docs/site-builder/` (or query the
> MCP server if connected).

Site Builder templates are server-rendered Twig and need **no** build step. The
frontend pipeline is **optional** — add it only when you want bundled CSS/JS/assets.

## Install

```bash
vendor/bin/tcms builder:frontend     # or: builder:init <starter> --frontend
```

This drops a customer-editable `frontend/` directory containing a Vite project
(`vite.config.js`, source CSS/JS). It is your code — edit it freely.

## Build

```bash
cd frontend
npm install
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

- `frontend/` is present only if installed; absence is normal for template-only sites.
- Build output in `public/assets/` is git-ignored — commit `frontend/` source, not output.
- For deeper config see `vendor/totalcms/cms/resources/docs/site-builder/frontend.md`.
