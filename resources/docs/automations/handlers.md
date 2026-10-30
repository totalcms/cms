---
title: Writing Handlers
description: "Write the PHP handler an automation runs: a closure that receives the AutomationContext API for reading, saving, and removing objects."
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
| `$ctx->mailer` | Send a [Mailer](docs/notifications/mailer) email — `sendEmail($mailerId, $data)` |
| `$ctx->http` | Call a service whose URL you wrote (an API, a webhook) — `request($method, $url, $options)` returns `statusCode` and `body`. See below |
| `$ctx->publicHttp` | GET a URL someone else typed — `get($url, $options)` returns `statusCode` and `body`. Public addresses only (see below) |
| `$ctx->config` | Core configuration |
| `$ctx->logger` | PSR-3 logger writing to `jobs.log` (channel `automations`) |
| `$ctx->trigger` | The trigger row that fired this run (e.g. `$ctx->trigger['type']`) |
| `$ctx->args` | Caller inputs — webhook query + body, or manual *Run now* args |
| `$ctx->event` | Event payload (`collection`, `id`) — event triggers only |
| `$ctx->request` | The PSR-7 request — webhook triggers only |

`$ctx->request` and `$ctx->event` are `null` for schedule runs.

## Calling a service

Use `$ctx->http` for a URL you wrote yourself in the handler or in config: a mailing-list API, a payment provider, a Slack webhook, a service on your own network. Use `$ctx->publicHttp` (below) for a URL that came from content. Don't create your own Guzzle or curl client: `$ctx->http` is the one core uses, and tests replace it so a handler under test never reaches the network.

```php
return function ($ctx) {
    $sendy = $ctx->config->automations['secrets']['sendy'] ?? [];
    $user  = $ctx->objectFetcher->fetchObject($ctx->event['collection'], $ctx->event['id']);

    try {
        $response = $ctx->http->request('POST', $sendy['url'] . '/subscribe', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => http_build_query([
                'api_key' => $sendy['apiKey'],
                'list'    => $sendy['list'],
                'email'   => $user['email'],
                'boolean' => 'true',
            ]),
            'timeout' => 15,
        ]);
    } catch (\RuntimeException $e) {
        throw new \RuntimeException('Sendy is unreachable: ' . $e->getMessage());
    }

    return ['status' => $response->statusCode, 'body' => $response->body];
};
```

Options: `headers` (name => value), `body` (a string: encode JSON with `json_encode()`, a form with `http_build_query()`), `timeout` and `connect_timeout` (seconds), `follow_redirects` (`false` by default, `true` or a number), `verify_ssl` (default `true`), `user_agent`, `max_bytes`. A 4xx or 5xx answer is returned, not thrown: check `$response->isSuccess()`. A connection failure throws `RuntimeException`. `$response->json()` decodes a JSON body.

## Keeping API keys out of the handler

Don't write a key into the handler. Handlers live in `tcms-data`, which travels with sync and JumpStart and is often editable in the admin. Put keys in `config/tcms.php`, under `automations.secrets`, and read them from `$ctx->config`:

```php
// config/tcms.php
return [
    'automations' => [
        'secrets' => [
            'sendy' => [
                'url'    => 'https://newsletter.example.com',
                'list'   => 'aBc123',
                'apiKey' => getenv('SENDY_API_KEY') ?: '',
            ],
        ],
    ],
];
```

`config/tcms.php` is PHP, so the value can come from the server's environment (`getenv()`) or from a file you keep out of git (`require __DIR__ . '/secrets.php'`). Core does not read `.env` files. Core never uses the `secrets` block itself, and the admin's server check hides every value in it.

## Fetching a URL from content

When the URL comes from content (a member's website, a feed someone added, a link in a form), fetch it with `$ctx->publicHttp`, not curl or Guzzle directly. A URL like that can point at your own server: `http://localhost/`, a private network address, or the cloud metadata service at `169.254.169.254`. `publicHttp` refuses all of them:

- The host must resolve only to public addresses, and the request connects to the address that was checked, so DNS cannot be switched between the check and the request.
- Redirects are followed one at a time, and each one is checked the same way.
- Only `http` and `https`, with no username or password in the URL.
- A 10 second timeout, and the body is read up to 2 MB; the rest is dropped.

```php
return function ($ctx) {
    try {
        $response = $ctx->publicHttp->get($url, ['timeout' => 8, 'max_bytes' => 1_000_000]);
    } catch (\RuntimeException $e) {
        return ['unreachable' => $e->getMessage()];   // refused, or the site is down
    }
    if (!$response->isSuccess()) {
        return ['status' => $response->statusCode];
    }
    // $response->body is the page
};
```

Options: `timeout` (seconds), `max_bytes`, `max_redirects` (default 3), `user_agent` and `headers` (name => value).

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
- **Throwing** marks the run **failed**. In development the error surfaces loudly; in production it's contained, optionally emailed via the automation's error mailer, and counts toward [auto-disable](docs/automations/overview#failure-handling).

## The handler advisory

When you save a handler, the editor scans it for high-risk calls — `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `eval`, backticks, `base64_decode`, remote `file_get_contents`, and similar — and shows a **non-blocking** advisory listing what it found. It's a heads-up, not a block: many legitimate handlers use these. Review before relying on a handler that reaches for them.
