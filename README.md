# Total CMS

<p align="center">
  <strong>A modern, flat-file Content Management System for PHP</strong>
</p>

<p align="center">
  <a href="https://totalcms.co">Website</a> &bull;
  <a href="https://docs.totalcms.co">Documentation</a> &bull;
  <a href="https://totalcms.co/pricing">Pricing</a>
</p>

---

## About

Total CMS is a powerful content management system built on PHP 8.2+ and the Slim 4 framework. It uses flat-file
JSON storage instead of a traditional database, making it simple to deploy and maintain.

- **No database required** &mdash; content is stored as JSON files
- **13 built-in collection types** &mdash; blog, image, gallery, file, and more
- **Custom collections** &mdash; define your own content types with JSON schemas
- **Twig templating** &mdash; with 40+ custom filters and functions
- **RESTful API** &mdash; full CRUD with authentication
- **Admin interface** &mdash; form builder, data tables, media management
- **CLI tools** &mdash; manage content, run imports, and clear caches from the terminal

## Requirements

- PHP 8.2+
- Composer 2.0+
- Apache or Nginx with URL rewriting
- PHP extensions: GD or ImageMagick, JSON, Fileinfo, OpenSSL

## Installation

```bash
composer create-project totalcms/totalcms mysite
cd mysite
```

Point your web server's document root to the `public/tcms/` directory, then navigate to `/admin` to complete setup.

For detailed installation and web server configuration, see the [Installation Guide](https://docs.totalcms.co/getting-started/installation).

## Twig Templates

```twig
{% set posts = cms.blog() %}
{% for post in posts %}
    <article>
        <h2>{{ post.title }}</h2>
        {{ post.content|markdown }}
    </article>
{% endfor %}
```

See the full [Twig documentation](https://docs.totalcms.co/templating) for available functions, filters, and tags.

## CLI

Total CMS includes a command-line tool for common tasks:

```bash
vendor/bin/tcms cache:clear
vendor/bin/tcms import:csv blog data.csv
vendor/bin/tcms jumpstart:export backup.zip
```

## Documentation

Full documentation is available at [docs.totalcms.co](https://docs.totalcms.co).

## Support

- [Documentation](https://docs.totalcms.co)
- [Email Support](mailto:support@totalcms.co)

## License

Total CMS is commercial software. A license is required for production use. See [LICENSE.md](LICENSE.md) for terms.

Free 45-day trials are available &mdash; no credit card required. Visit [totalcms.co](https://totalcms.co) for details.
