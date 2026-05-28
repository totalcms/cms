---
title: "Algolia Search (Bundled Extension)"
description: "Pluggable search provider backed by Algolia. Routes MCP search tools (and future site-wide search consumers) through Algolia's hosted keyword + neural search instead of T3's built-in text search."
since: "3.5.0"
---

# Algolia Search

`totalcms/algolia-search` — bundled with Total CMS. Implements the [`SearchProvider`](docs/extensions/extension-points) interface against [Algolia](https://www.algolia.com), letting you swap T3's built-in text search for Algolia's hosted search index. When the active search provider is `algolia`, every MCP `search_collection` call (and any future REST or site-wide search consumer) routes through your Algolia account.

Pro edition or higher is required.

## Why use it

- **Better relevance.** Algolia's typo-tolerance, prefix matching, and synonym handling outperform the built-in text index for anything beyond exact-match lookups.
- **Faster on large collections.** The built-in text search scans T3's in-memory index per query. Algolia keeps your content in a hosted index optimized for sub-50ms response times regardless of size.
- **Hybrid search.** Algolia combines keyword and neural (semantic) ranking in one query — useful for AI-agent searches via MCP where the user's wording rarely matches your content exactly.
- **Falls back automatically.** If your Algolia keys are unset or the service is unreachable, `SearchService` falls through to T3's built-in `text` provider. Configuration errors degrade gracefully — they don't break search.

## Use cases NOT to use this for

- **Sites with a handful of objects.** Built-in text is fine under a few thousand items per collection. Algolia is overkill until you outgrow it.
- **Air-gapped or self-hosted-only requirements.** Algolia is a hosted SaaS. If your data can't leave your servers, stick with the built-in text provider or wait for a self-hosted provider (Meilisearch, Typesense) to ship as a separate extension.
- **Free-tier sites with infrequent search.** The built-in provider has no API calls and no per-search cost. Only switch when relevance / scale demand it.

## Enabling

1. **Sign up for Algolia** at [algolia.com](https://www.algolia.com) (free tier covers most hobbyist + small-business sites). Create an application and note the Application ID.
2. **Admin → Extensions**, find **Algolia Search**, click **Enable**. Or `tcms extension:enable totalcms/algolia-search`.
3. **Admin → Extensions → Algolia Search → Settings**, fill in:
   - **Application ID** — shown in your Algolia dashboard as "Application ID"
   - **Admin API Key** — under **API Keys**, the row marked "Admin"
   - **Search-Only API Key** — the "Search-Only" row (safe to expose client-side once site-wide search ships; not used by T3 directly today)
   - **Index Name** — defaults to `tcms_content`. Change it if you share an Algolia account across multiple sites.
4. **Admin → Settings → Search Providers**, set **Active Provider** to `algolia`.
5. **Push existing content into the index:**

   ```bash
   tcms search:reindex --all
   ```

   For one collection at a time:

   ```bash
   tcms search:reindex blog
   ```

From this point on, content saved via the admin (or any code path that goes through `ObjectSaver`) is queued for re-indexing automatically — see [Content lifecycle](#content-lifecycle).

## Settings

All settings live under **Extensions → Algolia Search → Settings** and persist to `tcms-data/.system/extension-settings/totalcms/algolia-search.json`.

| Setting | Description |
|---------|-------------|
| **Application ID** | Your Algolia app ID. Empty = extension is "installed but inactive" — `isAvailable()` returns false and the provider gets skipped. |
| **Admin API Key** | Used by T3 for indexing + deletion. Keep this secret — anyone with it can modify your Algolia data. |
| **Search-Only API Key** | Used for read-only queries. Safe to expose in client-side code if you later wire up a browser-side search UI. |
| **Index Name** | Algolia index that holds T3 content. One index per site is the simplest model; share an Algolia account across sites by giving each its own index name. |

When any of the three string keys are blank, the provider is registered but reports `isAvailable() = false`. `SearchService` detects this and falls back to the built-in `text` provider — so a misconfigured install behaves the same as not having the extension enabled at all.

## Content lifecycle

Once Algolia is the active provider, T3 keeps your index in sync automatically:

1. **Save an object** — `ObjectSaver` fires the `object.created` / `object.updated` event.
2. **`ContentChangeListener`** picks it up, enqueues a `search.reindex` job in T3's job queue.
3. **Next `tcms jobs:process` run** (or your cron, or a manual run) drains the queue, calling `AlgoliaSearchProvider::index()` for each affected object.
4. **Delete an object** — same flow via `object.deleted`, calling `AlgoliaSearchProvider::delete()`.

Indexing happens out-of-band on the job queue so user-facing saves stay fast. Worst case, search results lag the source-of-truth by however long your queue takes to drain.

### Record shape

Each T3 object becomes one Algolia record:

| Field | Source |
|-------|--------|
| `objectID` | `"{collection}/{id}"` — unique across the entire index |
| `collection` | T3 collection ID, indexed as a faceted attribute for fast per-collection filtering |
| (everything else) | The object's persisted fields, copied verbatim |

The collection facet is what makes per-collection search work — when MCP queries with `collection: 'blog'`, the provider issues an Algolia query filtered on `collection:"blog"`.

## Per-collection override

You can opt a single collection out of Algolia even when the site-wide active provider is `algolia`. In the collection meta, set **Search Provider Override** to **Text Search (built-in)**. Useful for:

- **SKU-heavy collections** where exact substring matches are what you actually want (Algolia's typo-tolerance hurts more than it helps).
- **Tiny collections** where the round-trip to Algolia costs more than scanning T3's in-memory index.

The override is a flat field on the `mcp-collection` card schema — see [MCP Server](docs/mcp/server) for the broader settings layout.

## Reindexing manually

```bash
# Reindex everything
tcms search:reindex --all

# Reindex one collection
tcms search:reindex blog

# Combine with --quiet for cron use
tcms search:reindex --all --quiet
```

Use these when:

- **First-time setup** — push existing content into a new Algolia index.
- **After bulk imports** — JumpStart imports, CSV imports, and the like queue reindex jobs the same way single-object saves do, but `--all` is faster for huge migrations.
- **Schema changes** — adding a new field that should be searchable. Algolia doesn't know about T3's schema changes; a reindex rewrites every record with the new shape.

The CLI processes records in batches and is safe to interrupt — re-running picks up where it left off.

## Failure modes

All silent — Algolia outages should never break the live admin:

- **Keys blank** → provider registered but `isAvailable()` returns false → `SearchService` falls back to `text`.
- **Network failure during search** → exception caught by `SearchService`, falls back to `text`.
- **Network failure during indexing** → the job stays in the queue, retried on the next `jobs:process` run.
- **Algolia returns an HTTP error** → logged via T3's logger, treated the same as a network failure (fall back / requeue).

The active-provider setting is a hint, not a hard requirement. If Algolia is unavailable, search keeps working — it just uses the built-in text provider until Algolia comes back.

## Composing with other extensions

Multiple search providers can be registered at once (Algolia + your own custom provider, say) — only one is the *active* provider at a time. The registry collision policy is strict-deny: a third-party extension trying to register `id: 'algolia'` while this extension is enabled will be rejected at boot time and logged. Pick a unique provider id.

## Disabling

Bundled extensions can't be removed (they ship in the package), but they can be disabled. **Admin → Extensions → Algolia Search → Disable**, or:

```bash
tcms extension:disable totalcms/algolia-search
```

When disabled, Algolia disappears from the **Settings → Search Providers** dropdown. If `algolia` was the active provider, `SearchService` falls back to `text` automatically — no broken search, no admin warnings.

Records left in your Algolia index aren't deleted when you disable the extension. Drop the index in the Algolia dashboard if you want to clean up.

## Implementation notes

- The provider class is `TotalCMS\Bundled\AlgoliaSearch\Service\AlgoliaSearchProvider` at `resources/extensions/totalcms/algolia-search/Service/AlgoliaSearchProvider.php`.
- It uses the Algolia v4 PHP SDK (`algolia/algoliasearch-client-php`), which exposes a flat client API — `searchSingleIndex()`, `saveObject()`, `deleteObject()` — rather than v3's `initIndex()` pattern.
- The bundled extension is Pro-edition gated. On Standard / Lite installs, `register()` short-circuits and the provider never enters the registry. The Extensions page still lists it; it just doesn't activate.

Source: `resources/extensions/totalcms/algolia-search/`

## See also

- [Extension Points](docs/extensions/extension-points) — including `registerSearchProvider()` for shipping your own search provider
- [Bundled Extensions](docs/extensions/bundled) — concept and list of all bundled extensions
- [MCP Server](docs/mcp/server) — the primary consumer of search in 3.5
