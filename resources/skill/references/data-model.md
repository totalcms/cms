# Data model reference: collections, schemas, objects

> This is a summary. For exhaustive detail (every field type and option), read the
> on-disk docs that ship with the package at
> `vendor/totalcms/cms/resources/docs/{collections,schemas,fields}/` (or query the
> MCP server if connected).

Three concepts:
- **Schema** — the shape: which fields an object has and their types. JSON under
  `tcms-data` / managed via `schema:*`. 38 reserved schemas (blog, image, gallery,
  builder-page, seo-site, automations, …) plus your own custom schemas.
- **Collection** — a named bucket of objects bound to a schema (e.g. `blog` uses the
  `blog` schema). Managed via `collection:*`. Carries its own settings: URL, sort,
  public operations, sitemap, SEO mapping, MCP exposure.
- **Object** — one record (one JSON file) in a collection.

## Inspect

```bash
vendor/bin/tcms schema:list --json
vendor/bin/tcms collection:list --json
vendor/bin/tcms collection:get blog --json
vendor/bin/tcms object:list blog --json
vendor/bin/tcms object:get blog my-post --json
```

## Create a schema

**Start from the reference schema.** Total CMS ships a reserved schema named
`totalcms` whose 44 properties demonstrate every built-in field type with its
settings, and whose help text says what each field is for. Read it first and
copy property definitions from it instead of writing them from memory:

```bash
vendor/bin/tcms schema:get totalcms --json      # or MCP: get_schema { id: "totalcms" }
```

Then author your schema JSON and import it:

```bash
vendor/bin/tcms schema:import my-schema.json
```

If you edit a schema JSON file **in place** instead, nothing re-validates it —
run `vendor/bin/tcms schema:lint <id>` afterwards.

### Modelling rules

Match the field to the **shape of the value**. `text` is the last resort, not
the default. A date in a text field cannot be filtered by month; a number in
a text field sorts as a string; a yes/no in a text field is a typo waiting to
happen.

| The value is… | Use | Not |
|---|---|---|
| the identity / URL slug | `id` with `"settings": {"autogen": "${title}"}` | `text` |
| a headline, name, label | `text` | |
| a summary, excerpt, plain note | `textarea` | `text`, `styledtext` |
| a formatted body | `styledtext` | `textarea` |
| a date / a timestamp | `date` / `datetime` | `text` |
| yes or no (featured, draft, in stock) | `toggle` | `text`, `select` |
| one of a fixed list (status, size, layout) | `select` (`radio` for 2–5 shown at once) | `text` |
| several of a fixed list | `checklist` | `list` |
| tags / categories editors grow over time | `list` + `"settings": {"propertyOptions": true}` | `select` |
| a count, rating, sort order | `number` | `text` |
| money | `price` | `number` |
| an email / a URL / a phone | `email` / `url` / `phone` | `text` |
| one image / several / a file / a file library | `image` / `gallery` / `file` / `depot` | a path in `text` |
| a hosted video | `video` | `url` |
| a fixed group of related fields (address, hero) | `card` | flat `addressLine1`, `addressCity`… |
| repeating structured items (FAQs, team, line items) | `deck` (`deckTable` for short rows) | a `list`, a `json` |
| an API key / token | `secret` | `text` |
| a colour | `color` | `text` |
| SEO metadata for a public page | a `card` with `"schemaref": ".../schemas/seo.json"` | separate `metaTitle` text fields |

Omit `type` when the field implies it — the saver fills it in from `field`.

**"Category" means five different things.** Ask which before modelling it:

| Meaning | It is |
|---|---|
| blog-style categories/tags, several per object, editor-extensible | `list` + `propertyOptions: true` (how the built-in `blog` schema does it) |
| a dropdown with fixed choices, one per object | `select` with static `options` |
| several fixed choices per object | `checklist` with static `options` |
| grouping collections in the admin sidebar | the collection's `category` setting |
| grouping schemas in the schema editor | the schema's top-level `category` (`Internal` is reserved — never use it) |

**Public pages need the SEO card.** Any schema whose objects render as pages
should carry `seo` (a `card` referencing the reserved `seo` schema), a
full-width `formgrid` row (`seo seo`), **and** `seo` in the `index` array —
without the index entry, noindex is ignored by the sitemaps. The collection
also needs its **URL** set, or objects get no canonical, no `og:url` and no
Article JSON-LD. Details: `docs/site-builder/seo.md`.

### Descriptions are read by agents, not just editors

Five slots feed the MCP tool catalog that other AI agents see: the schema
`description`, each property's `help` (or `mcp.description` when it should
differ from the editor-facing hint), the collection's `mcp` card
(`access`, `description`, `resource`) and each saved-query tool's
`description`. Generic text there produces generic content later.

Write each as a brief to a writer who cannot see the site: what the value is
for, its shape, what good looks like, constraints the field type does not
imply. Not "The title" but "Headline shown in listings and as the page
title. Sentence case, under 70 characters, no trailing period." Not
"Categories" but "Editorial sections this post belongs to, usually one. Pick
from the existing values; add a new one only for a genuinely new topic."
Examples for every slot: `docs/mcp/agent-editing.md`, section "Writing for agents".

Do not confuse these with a collection's general **Description** (admin
dashboard), the SEO mapping's **Description Property** (which object property
becomes the meta description), or a Site Builder page's `description` (its
meta description). None of those reach an agent.

### Definition of done for schema work

```bash
vendor/bin/tcms schema:lint <id> --strict
```

Zero errors and zero warnings — every property has help text and the schema
has a description — **before** reporting the schema as finished. Do this on
the first pass; do not leave descriptions for a follow-up.

## Create / import content

One object — `object:create` (file path, or `-` for stdin):

```bash
echo '{"id":"hello","title":"Hello","body":"..."}' | vendor/bin/tcms object:create blog -
```

Change part of an existing object — `object:patch` merges only the fields you send:

```bash
echo '{"featured":true}' | vendor/bin/tcms object:patch blog hello -
```

Many objects — **`collection:import`** from JSON/CSV (JSON is an array of
objects conforming to the collection's schema):

```bash
vendor/bin/tcms collection:import blog posts.json
```

```json
[ { "id": "hello", "title": "Hello", "body": "..." } ]
```

Full-site bulk seeding — **`jumpstart:import`**. The **admin UI** works for
all of it, of course.

## Export / query

```bash
vendor/bin/tcms collection:query blog --json        # filters + pagination
vendor/bin/tcms collection:export blog --json        # JSON | CSV | ZIP
vendor/bin/tcms object:export blog hello             # single object (+assets as ZIP)
```

## Storage format

A collection stores objects as JSON by default. Set its `format` to
`markdown` (or run `vendor/bin/tcms collection:convert <id> --to=markdown`) to
store each object as a `.md` file with YAML frontmatter and the `content`
property as the body — the right shape for docs sites and markdown blogs.
Details: `docs/collections/storage-format.md`.

## Repair

If an index or file metadata gets out of sync:

```bash
vendor/bin/tcms repair:index blog
vendor/bin/tcms repair:files
```

Exhaustive reference: `vendor/totalcms/cms/resources/docs/{collections,schemas,fields}/`.
