# {{name}}

{{description}}

A ready-made website built on [Total CMS](https://totalcms.co).

## Install

```bash
composer create-project {{name}} mysite
```

Point your web server's document root at `mysite/public/`, then visit the site: the setup wizard creates your admin account.

No Composer? Download the zip from this repository's Releases page. It includes `vendor/`, so you only need to upload it.

## What's inside

- `builder/` holds the page templates, written in Twig and commented throughout.
- `tcms-data/` holds the schemas, collections, pages and demo content.
- `public/assets/` holds the CSS. Change the site's colors and fonts in `theme.css`.

The demo photos are listed in `CREDITS.md`. Replace them with the client's own before launch.

## Requirements

PHP 8.2 or later, and a Total CMS license for the domain you deploy to.
