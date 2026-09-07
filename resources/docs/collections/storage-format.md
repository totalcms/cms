---
title: "Storage Format"
description: "Store a collection's objects as markdown files with YAML frontmatter instead of JSON"
related:
  - collections/settings
  - operations/deployment
  - fields/styled-text
---

# Storage Format

Every collection stores its objects as flat files on disk. By default that's JSON — `{collection}/{id}.json` — but a collection can instead be created as **markdown**, storing each object as `{collection}/{id}.md`: YAML frontmatter for every property, plus a body written in plain text. This is for content people would rather edit in a text editor or a git repository than through a JSON blob — documentation sites, markdown-first blogs, and anything else where the "content" of an object deserves to look like a document rather than a data record.

## Choosing a format

**Storage Format** is a setting on the collection, chosen when you create it: `json` (the default, and today's behavior) or `markdown`. It appears next to `singleton` in the collection form.

The format is **fixed after creation**. Once a collection exists, the setting shows in the form as disabled, with a hint pointing at the convert command — and posting a changed `format` through the collection API is rejected with a 400. This is deliberate: the setting on disk and the files on disk must always agree, and the only thing allowed to change both together is `tcms collection:convert`. The same schema can back a JSON collection and a markdown collection — format is a property of the collection, not the schema.

## The file

In markdown mode, an object is a single `.md` file. Frontmatter is YAML holding every property except the body; the body is the raw value of the schema's `content` property, when `content` is string-typed. Here's `load-more.md` from a documentation collection:

```markdown
---
id: load-more
title: "Load More"
description: "Add HTMX-powered infinite scroll…"
related:
  - twig/render
  - twig/cmsgrid-tag
audience: intermediate
updated: 2026-08-27
---

# Load More

Total CMS provides "load more" helpers…
```

`content` becomes the body only when the schema declares it as `type: string`, or gives it one of the field types `text`, `textarea`, `markdown`, or `styledtext`. If the schema has no `content` property, or `content` isn't one of those, the file is frontmatter only — everything (including a non-string `content`, if the schema happens to have one) stays in the YAML block, and the file just has no body section. A schema whose `content` is a `styledtext` (Tiptap) field still works: the body section holds that field's HTML, written verbatim. It reads oddly if you're expecting markdown syntax, but it round-trips losslessly, and Total CMS never asks you to open the file — the admin form still edits it as rich text.

The file name — not any `id:` line in the frontmatter — is always the object's id. An `id:` property is optional in hand-written frontmatter, and if it's present but disagrees with the file name, it's ignored; renaming the file is the only way to change an object's id.

Multi-line frontmatter strings (a long `description`, for example) are written as YAML literal blocks (`key: |`) rather than escaped one-liners, so they stay readable on disk. A schema with no string-typed `content` still gets a full frontmatter block holding every one of the object's properties — there's simply no body section after it, not an empty frontmatter block. The body itself, when there is one, is written verbatim with exactly one trailing newline; extra blank lines at the end of a textarea or markdown field's value are not preserved on disk the way they would be in a JSON string.

## Editing files by hand

This is the point of the feature, and the one real caveat: a `.md` file edited outside Total CMS bypasses schema validation entirely. There's no field-type checking, no required-property enforcement — whatever YAML and body text you write is what gets parsed back.

A hand-edited file is also **invisible until the index is rebuilt** — this is exactly today's behavior for JSON files edited by hand, now documented as the expected workflow:

```
edit → commit → deploy → tcms repair:index {collection}
```

(Or, for edits made through the admin rather than by hand, the collection's `queueRebuildOnSave` setting queues the rebuild automatically.) `tcms repair:index` also refreshes any objects that were already cached from before the edit, right after it rebuilds the index, so a request in flight can't keep serving the old contents. The admin's Rebuild Index button (`PUT /collections/{collection}/index`) does the same for **markdown** collections only: those are the ones you edit by hand, and clearing a large JSON collection's object cache on every click would cost far more than it helps. An ordinary index rebuild on an object save never clears object caches.

If a hand-edited file can't be parsed — broken YAML, a malformed frontmatter fence — it is **skipped** when the index is rebuilt rather than failing the whole build. The skip is logged with a warning naming the file ("Skipping unreadable object file"), so a typo in one page doesn't take down the rest of the collection; it just won't show up in the index (or the admin, API, or Twig) until it's fixed and the index is rebuilt again. `tcms repair:index` also prints which ids were skipped (and includes them under `skipped` in `--json` output), so you don't have to go looking in the log to find the typo.

Concurrency note: converting a collection (below) is safe against interruption, but it isn't safe against a simultaneous edit — an object saved through Total CMS while the converter happens to be rewriting that same object can lose whichever write loses the race. Run `collection:convert` in a quiet window (no admin/API traffic against the collection) rather than during normal editing.

## Converting an existing collection

To change an existing collection's format, use the CLI:

```bash
tcms collection:convert docs --to=markdown
tcms collection:convert docs --to=markdown --dry-run
tcms collection:convert docs --to=json
```

The collection's `format` setting flips **first**, before any object is rewritten — so every new write, from this command or anything else, immediately lands in the target format. Then each object still in the old format is rewritten: its new file is written before its old file is deleted, so every object stays readable throughout the run regardless of which format it's currently in. Once all objects are converted, the index is rebuilt.

Because which objects still need converting is decided by each file's actual extension rather than by the (already-flipped) setting, the command is **safe to interrupt and re-run**: an object already in the target format is recognized and **skipped**, so re-running after an interruption (or just running it again) finishes only what's left. Human-readable output reports how many objects were converted, how many were already in the target format and skipped, and lists any that failed; `--json` includes `converted`, `skipped`, and `failed` counts/lists.

An object that can't be read (unparseable file) or can't be written (fails schema validation on save) is reported as failed and left as-is, rather than aborting the whole run — the command exits non-zero when anything failed, and each failure line says which it was ("Could not read '…'" vs "Could not write '…'"); `--json`'s `failed` is a map of id → `"read"`/`"write"` for the same reason. `--dry-run` reports what would happen without writing anything (and without flipping the setting). Assets folders (`{id}/`, holding uploaded images and files for an object) and external code-field sidecars are untouched by conversion — only the object's own JSON or markdown file moves.

## A documentation site

The immediate use case: `resources/docs/**/*.md` already looks exactly like a markdown collection. A `docs` schema with five frontmatter properties plus a `content` body reproduces the existing file layout with no file changes:

| Property | Field |
|---|---|
| `title` | text |
| `description` | text |
| `related` | list |
| `audience` | select |
| `updated` | date |
| `content` | markdown |

Since `content` is a `markdown` field (string-typed), it becomes the body of each `.md` file — the same body Total CMS's own docs already carry. Render it in a template with the `|markdown` filter (see [Twig Markdown Integration](docs/twig/markdown)):

```twig
{{ page.content|markdown }}
```

## What does not change

Everything above the object repository works exactly the same regardless of format: the REST API, MCP tools, Twig (`cms.collection.objects()`, filters, etc.), `tcms push`/`pull` sync, JumpStart import/export, zip exports, uploads, and the admin interface. All of it reads and writes through the same repository, and none of it needs to know or care which format a collection uses.
