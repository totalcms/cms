# Total CMS

<p align="center">
  <strong>A modern flat-file CMS for PHP &mdash; no database required</strong>
</p>

<p align="center">
  <a href="https://totalcms.co">Website</a> &bull;
  <a href="https://docs.totalcms.co">Documentation</a> &bull;
  <a href="https://totalcms.co/pricing/">Pricing</a> &bull;
  <a href="https://totalcms.co/trial/">Free trial</a>
</p>

---

## About

Total CMS is a content management system built on PHP 8.2+ and Slim 4 that stores content as
flat JSON files instead of in a database. There is no MySQL to provision, no migrations to run,
and no plugin ecosystem to keep patched &mdash; content lives on disk, so it version-controls,
backs up, and deploys like the rest of your code.

It is designed for web designers, freelancers, and agencies who want structured content and a
polished admin for their clients, on any commodity PHP host.

- **No database** &mdash; content is JSON on disk; deploy by copying files
- **33 built-in collection types** &mdash; blog, image, gallery, file, and more &mdash; plus unlimited custom schemas
- **Twig templating** &mdash; 89 custom filters and 49 functions on top of stock Twig 3
- **Site Builder** &mdash; add a page in the admin and it is live; no build or generate step
- **REST API** &mdash; full CRUD for headless use, with API-key or OAuth 2.1 authentication
- **Built-in MCP server** &mdash; exposes your site's collections and search to AI agents (Pro)
- **Automations** &mdash; schedule, webhook, and event-triggered handlers with a job queue
- **Extensions** &mdash; a register/boot lifecycle with capability-based permissions
- **Admin interface** &mdash; form builder with 20+ field types, media management, passkey login
- **CLI (`tcms`)** &mdash; collections, schemas, objects, import/export, sync, and deploys
- **Image processing** &mdash; on-the-fly resizing, watermarking, and OKLCH color handling

## Requirements

- PHP 8.2+ (8.4 supported)
- Composer 2.0+
- Apache or Nginx with URL rewriting
- PHP extensions: GD or ImageMagick, JSON, Fileinfo, OpenSSL

No database server. No Node.js runtime in production.

## Installation

```bash
composer create-project totalcms/totalcms mysite
cd mysite
```

Point your web server's document root at `public/`, then open the site in a browser &mdash; the
setup wizard handles the admin account, data directory, and license from there.

The installer also offers a **subpath** layout, which serves Total CMS from `/tcms/` and leaves
`public/` free for your own frontend build. See the
[Installation Guide](https://docs.totalcms.co/get-started/installation/) for web server
configuration.

## Twig templates

```twig
{% for post in cms.collection.objects('blog') %}
    <article>
        <h2>{{ post.title }}</h2>
        {{ post.content|markdown }}
    </article>
{% endfor %}
```

See the [Twig documentation](https://docs.totalcms.co/twig/filters/) for the full filter and
function reference.

## CLI

```bash
vendor/bin/tcms collection:list
vendor/bin/tcms jumpstart:export backup.json
vendor/bin/tcms cache:clear
vendor/bin/tcms deploy
```

## For AI agents

Total CMS is built to be worked on by coding agents:

- **[llms.txt](https://docs.totalcms.co/llms.txt)** and
  **[llms-full.txt](https://docs.totalcms.co/llms-full.txt)** &mdash; the complete documentation
  in one fetch
- **Agent skill** &mdash; installed into `.claude/skills/` automatically on `composer install`,
  covering collections, schemas, the CLI, and the Site Builder workflow
- **Built-in MCP server** &mdash; point an agent at a live site to query its collections and
  schemas directly (Pro edition)

## Documentation

Full documentation is at [docs.totalcms.co](https://docs.totalcms.co).

## Support

- [Documentation](https://docs.totalcms.co)
- [Email Support](mailto:support@totalcms.co)

## License

Total CMS is commercial software. A license is required for production use. See [LICENSE.md](LICENSE.md) for terms.

Free 45-day trials are available &mdash; no credit card required. Visit
[totalcms.co](https://totalcms.co) for details.
