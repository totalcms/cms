---
title: Writing Handlers
description: The PHP handler and the AutomationContext API.
related:
  - automations/triggers
  - automations/overview
  - fields/code-editor
---

# Writing Handlers

A handler is a PHP file that **returns a closure**. The closure receives a single argument — an `AutomationContext` — and runs when any of the automation's triggers fire.

```php
<?php

return function ($ctx) {
    // ... your logic ...

    return ['ok' => true];
};
```

The handler is stored in its own `.php` file next to the automation object (an *external* code field), so it's edited like any code field in the admin but never bloats the collection JSON. It travels with the object through Sync and JumpStart.

## The AutomationContext

`$ctx` is the **only** thing a handler receives — pre-wired services plus the trigger payload. No need to reach into a container.

**Objects**

| Property | What it is |
|----------|------------|
| `$ctx->objectFetcher` | Read objects — `fetchObject($collection, $id)` |
| `$ctx->objectSaver` | Create objects — `saveObject($collection, $data)` |
| `$ctx->objectUpdater` | Update objects — `updateObject($collection, $id, $data)` |
| `$ctx->objectRemover` | Delete objects — `deleteObject($collection, $id)` |
| `$ctx->objectCloner` | Duplicate an object — `cloneObject($from, $to)` |
| `$ctx->propertyIncrementer` | Atomic counters — `incrementProperty($collection, $id, $property, $amount = 1)` / `decrementProperty(...)` |

**Deck items** (cards/repeaters inside an object)

| Property | What it is |
|----------|------------|
| `$ctx->deckItemSaver` | Add a deck item — `saveDeckItem($collection, $objectId, $property, $itemId, $itemData)` |
| `$ctx->deckItemUpdater` | Update a deck item — `updateDeckItem(...)` |
| `$ctx->deckItemRemover` | Remove a deck item — `removeDeckItem($collection, $objectId, $property, $itemId)` |
| `$ctx->deckItemFetcher` | Read deck items — `fetchDeckItem(...)`, `fetchAllDeckItems($collection, $objectId, $property)` |

**Querying**

| Property | What it is |
|----------|------------|
| `$ctx->indexReader` | Read a collection index — `fetchIndex($collection)` (returns a filterable collection) |
| `$ctx->indexSearcher` | Full-text-ish search — `search($collection, $query)`, `searchByProperty($collection, $property, $query)` |
| `$ctx->indexQueryService` | Structured query (filter/sort/paginate) — `query($collection, $params)` |
| `$ctx->indexBuilder` | Rebuild an index after batch writes — `buildIndex($collection)` |

**Collections & schemas**

| Property | What it is |
|----------|------------|
| `$ctx->collectionFetcher` | Inspect a collection — `fetchCollection($id)`, `collectionExists($id)` |
| `$ctx->schemaFetcher` | Inspect a schema — `fetchSchemaForCollection($collection)`, `schemaExists($id)` |

**Files & images**

| Property | What it is |
|----------|------------|
| `$ctx->fileSaver` | Store a file into a file field — `save(...)` |
| `$ctx->imageSaver` | Store/process an image into an image field — `save(...)` |

**Import & sync**

| Property | What it is |
|----------|------------|
| `$ctx->csvImporter` | Import a CSV — `import($collection, $file, $updateObject = false)` |
| `$ctx->jsonImporter` | Import JSON — `import($collection, $file, $updateObject = false)` |
| `$ctx->rssImporter` | Ingest an RSS feed — `import($feedUrl, $collection, $options = [])` |
| `$ctx->syncService` | Push/pull to another T3 install — `push(...)`, `pull(...)` |

**Mail, config & run payload**

| Property | What it is |
|----------|------------|
| `$ctx->mailer` | Send a [Mailer](../notifications/mailer.md) email — `sendEmail($mailerId, $data)` |
| `$ctx->config` | Core configuration |
| `$ctx->logger` | PSR-3 logger writing to `jobs.log` (channel `automations`) |
| `$ctx->trigger` | The trigger row that fired this run (e.g. `$ctx->trigger['type']`) |
| `$ctx->args` | Caller inputs — webhook query + body, or manual *Run now* args |
| `$ctx->event` | Event payload (`collection`, `id`) — event triggers only |
| `$ctx->request` | The PSR-7 request — webhook triggers only |

`$ctx->request` and `$ctx->event` are `null` for schedule runs.

## Example: digest email on a schedule

```php
<?php

return function ($ctx) {
    $posts = $ctx->indexReader->fetchIndex('blog')->objects
        ->where('draft', false)
        ->take(5);

    $ctx->mailer->sendEmail('weekly-digest', ['posts' => $posts->all()]);
    $ctx->logger->info('weekly digest sent', ['count' => $posts->count()]);

    return ['sent' => $posts->count()];
};
```

## Return values and errors

- **Return value** — whatever the closure returns is recorded on the run record and, for a synchronous webhook, becomes the HTTP response body. Return arrays/scalars (JSON-encodable).
- **Throwing** marks the run **failed**. In development the error surfaces loudly; in production it's contained, optionally emailed via the automation's error mailer, and counts toward [auto-disable](overview.md#failure-handling).

## The handler advisory

When you save a handler, the editor scans it for high-risk calls — `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `eval`, backticks, `base64_decode`, remote `file_get_contents`, and similar — and shows a **non-blocking** advisory listing what it found. It's a heads-up, not a block: many legitimate handlers use these. Review before relying on a handler that reaches for them.
