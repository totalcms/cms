# Algolia Search — Bundled T3 Extension

Reference SearchProvider implementation for T3 Phase 5. Routes MCP
search tools (and future REST / site-wide search consumers) through
[Algolia](https://www.algolia.com)'s hybrid keyword + neural search.

## Requirements

- Total CMS 3.5.0+
- Pro edition or higher (`EditionFeature::ALGOLIA_SEARCH`)
- An Algolia account (free tier works for hobbyist sites)

## Setup

1. Enable this extension from the admin Extensions page.
2. Open **Extensions → Algolia Search → Settings** and paste:
   - Application ID
   - Admin API Key (used for indexing — KEEP SECRET)
   - Search-Only API Key
   - Index Name (defaults to `tcms_content`)
3. Open **Settings → Search Providers** and set Active Provider to `algolia`.
4. Run `php resources/bin/tcms search:reindex --all` to push existing
   content into Algolia.

## License

Proprietary. Bundled with Total CMS.
