---
title: "Extension Events"
description: "Reference for all content events that extensions can subscribe to in Total CMS."
since: "3.5.0"
---

# Extension Events

Extensions can subscribe to events fired by Total CMS after core operations complete. Events are synchronous and fire after the operation succeeds. If a listener throws an exception, it is caught and logged without affecting the core operation or other listeners.

## Subscribing to Events

```php
public function register(ExtensionContext $context): void
{
    $context->addEventListener('object.created', function (array $payload): void {
        // Handle the event
    }, priority: 0);
}
```

The `priority` parameter controls execution order (lower numbers run first). Default is `0`.

## Who Caused the Event

Every payload carries an `actor` key alongside the event's own keys below: who caused the event, captured when it fired. A listener that defers its work, such as a queued automation or an audit log, still records the person rather than whoever processes the queue.

| Key | Type | Description |
|---|---|---|
| `kind` | `string` | `system`, `apikey`, `admin`, `member` or `anonymous` |
| `userId` | `string` | The user's ID, or `''` for system, API key and anonymous |
| `authCollection` | `string` | The auth collection the user belongs to |
| `clientId` | `string` | The OAuth client the user connected through, `webmcp` for a browser session over MCP, or `''` |
| `apiKeyId` | `string` | The API key's ID, or `''` |
| `initiator` | `?array` | For work the system does on someone's behalf (an automation an admin ran, a registration, a login stamp), the person who started it, in the same shape. `null` otherwise |

```php
$context->addEventListener('object.updated', function (array $payload): void {
    $who = $payload['actor']['initiator'] ?? $payload['actor'];
    // e.g. "alex (member) updated blog/post-1"
});
```

## Available Events

### `object.created`

Fired after a new object is saved to a collection.

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | Collection name |
| `id` | `string` | Object ID |

```php
$context->addEventListener('object.created', function (array $payload): void {
    $collection = $payload['collection'];
    $objectId = $payload['id'];
    // e.g., send a webhook notification
});
```

### `object.updated`

Fired after an existing object is updated.

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | Collection name |
| `id` | `string` | Object ID |

```php
$context->addEventListener('object.updated', function (array $payload): void {
    // e.g., clear a CDN cache for this object
});
```

### `object.deleted`

Fired after an object is deleted from a collection.

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | Collection name |
| `id` | `string` | Object ID |

```php
$context->addEventListener('object.deleted', function (array $payload): void {
    // e.g., clean up related data in your extension
});
```

### `collection.created`

Fired after a new collection is created.

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | Collection ID |

```php
$context->addEventListener('collection.created', function (array $payload): void {
    // e.g., set up default content for a new collection
});
```

### `collection.updated`

Fired after a collection record is written. That includes every content
save and every index build, which bump the collection's `count`,
`totalObjects` and `lastUpdated` — check `configChanged` if you only care
about the collection's settings.

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | Collection ID |
| `configChanged` | `bool` | `true` when the configuration changed (name, url, schema, MCP access, …); `false` for a metadata-only bump from an object write or index build |

```php
$context->addEventListener('collection.updated', function (array $payload): void {
    if (!($payload['configChanged'] ?? true)) {
        return; // just counters moving
    }
    // react to collection configuration changes
});
```

### `collection.deleted`

Fired after a collection is deleted.

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | Collection ID |

```php
$context->addEventListener('collection.deleted', function (array $payload): void {
    // e.g., clean up extension data related to this collection
});
```

### `import.created`

Fired **per object** when an importer creates a new object. Replaces `object.created` for the duration of an import — during import, `object.created` is suppressed for the importing collection so listeners can distinguish import-time writes from user-driven ones.

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | Collection ID |
| `id` | `string` | Object ID |
| `object` | `ObjectData` | Newly created object |

```php
$context->addEventListener('import.created', function (array $payload): void {
    // e.g., index the newly imported object in an external search service
});
```

Fired by: `ObjectImporter` (used by CSV, JSON, RSS, WordPress, URL, Alloy, Total CMS 1 imports), `JumpStartImporter`, `UrlImporter`.

### `import.updated`

Fired **per object** when an importer updates an existing object. Replaces `object.updated` during import (same suppression model as `import.created`).

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | Collection ID |
| `id` | `string` | Object ID |
| `object` | `ObjectData` | Updated object |
| `previous` | `?ObjectData` | Object state before the update (when available) |

```php
$context->addEventListener('import.updated', function (array $payload): void {
    // e.g., diff payload['object'] against payload['previous']
});
```

Fired by: `ObjectImporter` (CSV/JSON update mode), `DeckJsonImporter`, `DeckCsvImporter`.

### `import.completed`

Fired ONCE per batch import (CSV, JSON, or URL) after all objects in the batch are processed. Triggers a single index rebuild and auto-resumes the `object.*` event suppression for the collection.

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | Collection ID |
| `count` | `int` | Number of objects imported |
| `created` | `string[]` | IDs of newly created objects |
| `updated` | `string[]` | IDs of updated objects |

```php
$context->addEventListener('import.completed', function (array $payload): void {
    $collection = $payload['collection'];
    $count = $payload['count'];
    // e.g., send a notification that import is done

    // Act on specific updated objects
    foreach ($payload['updated'] as $id) {
        // e.g., send an email to updated members
    }
});
```

> **Why subscribe to `import.*` instead of `object.*`?** When a 10,000-row CSV imports, `object.created` would fire 10,000 times — but it doesn't, because the dispatcher suspends it. Subscribe to `import.created` if you want per-object import notifications; subscribe to `import.completed` if you only care about the batch summary; subscribe to both if you need both.

### `schema.saved`

Fired after a schema is created or updated.

| Key | Type | Description |
|---|---|---|
| `schema` | `string` | Schema ID |

```php
$context->addEventListener('schema.saved', function (array $payload): void {
    // e.g., regenerate a search index
});
```

### `schema.deleted`

Fired after a schema is deleted.

| Key | Type | Description |
|---|---|---|
| `schema` | `string` | Schema ID |

```php
$context->addEventListener('schema.deleted', function (array $payload): void {
    // e.g., remove cached data for this schema
});
```

### `template.saved`

Fired after a Builder template (`.twig` file) is written via `TemplateSaver`. Powers the Builder live-reload feature internally — extensions can listen too, e.g., to re-warm a template cache or trigger a downstream rebuild.

| Key | Type | Description |
|---|---|---|
| `id` | `string` | Template id without folder prefix (e.g., `about`, `partials/header`) |
| `folder` | `string\|null` | Optional sub-folder (`pages`, `layouts`, etc.) or `null` for root |
| `path` | `string` | Full path including folder (e.g., `pages/about`) |

```php
$context->addEventListener('template.saved', function (array $payload): void {
    // e.g., flush a template-derived cache key
});
```

### `user.login`

Fired after a user successfully logs in.

| Key | Type | Description |
|---|---|---|
| `user` | `string` | User ID or email |

```php
$context->addEventListener('user.login', function (array $payload): void {
    // e.g., track login activity
});
```

### `user.logout`

Fired after a user logs out.

| Key | Type | Description |
|---|---|---|
| `user` | `string` | User ID or email |

```php
$context->addEventListener('user.logout', function (array $payload): void {
    // e.g., clean up temporary data
});
```

### `extension.enabled`

Fired after an extension is enabled.

| Key | Type | Description |
|---|---|---|
| `id` | `string` | Extension ID (e.g. `vendor/name`) |

### `extension.disabled`

Fired after an extension is disabled.

| Key | Type | Description |
|---|---|---|
| `id` | `string` | Extension ID (e.g. `vendor/name`) |

### `devmode.enabled`

Fired after development mode is enabled.

| Key | Type | Description |
|---|---|---|
| `duration` | `int` | Duration in seconds |

```php
$context->addEventListener('devmode.enabled', function (array $payload): void {
    // e.g., enable verbose logging in your extension
});
```

### `devmode.disabled`

Fired after development mode is disabled.

The payload array is empty.

```php
$context->addEventListener('devmode.disabled', function (array $payload): void {
    // e.g., disable debug features in your extension
});
```

### `cache.cleared`

Fired after all caches are cleared.

| Key | Type | Description |
|---|---|---|
| `success` | `bool` | Whether all caches cleared successfully |

The payload also includes per-service results (e.g. `filesystem`, `redis`, `apcu`).

```php
$context->addEventListener('cache.cleared', function (array $payload): void {
    // e.g., clear your extension's own cache
});
```

### `access.denied`

Fired when the access checks refuse a request, or when the storage layer refuses a write the request's own checks let through. Who was refused is the payload's `actor`. An unauthenticated request to a page that needs a login does not fire it.

| Key | Type | Description |
|---|---|---|
| `resource` | `string` | What was asked for: `collection`, `schema`, `object`, … |
| `operation` | `string` | `create`, `read`, `update`, `delete`, … |
| `reason` | `string` | The check that refused: `group`, `owner`, `protected`, or `''` |
| `collection` | `string` | The collection, when the request named one |
| `id` | `string` | The object ID, when the request named one |
| `route` | `string` | The route name, or `''` below the HTTP layer |
| `method` | `string` | The HTTP method, or `''` below the HTTP layer |
| `path` | `string` | The request path, or `''` below the HTTP layer |

```php
$context->addEventListener('access.denied', function (array $payload): void {
    // e.g. alert when one user is refused repeatedly
});
```

### `file.downloaded`

Fired when a file is served as an attachment from a `/download/...` URL, after its protection (access groups, password) passed. Who downloaded it is the payload's `actor`; for a visitor who is not signed in that is `anonymous`. Inline streams from `/stream/...` do not fire it, since a media player makes many range requests for one play.

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | The collection |
| `id` | `string` | The object ID |
| `property` | `string` | The file, depot or styled-text property |
| `filename` | `string` | The file name the download was saved as |
| `subpath` | `string` | The depot subfolder, or `''` |
| `protected` | `bool` | Whether the file is limited to access groups or a password |

```php
$context->addEventListener('file.downloaded', function (array $payload): void {
    if ($payload['actor']['kind'] === 'member') {
        // e.g. count downloads per member
    }
});
```

## Listener Isolation

Each listener is wrapped in a try/catch. If your listener throws:

1. The exception is logged to `extensions.log` in the [logs directory](docs/operations/filesystem)
2. Other listeners for the same event continue executing
3. The core operation that triggered the event is not affected (it already completed)

This means you should not rely on events for critical side effects that must succeed. Events are best for notifications, caching, analytics, and other non-critical reactions to content changes.

## Priority

Listeners with lower priority numbers execute first:

```php
// Runs first
$context->addEventListener('object.created', $listenerA, priority: 10);

// Runs second
$context->addEventListener('object.created', $listenerB, priority: 20);
```

If two listeners have the same priority, they execute in the order they were registered.

## Sending Notifications from Event Listeners

Extensions can use events to trigger notifications automatically when content changes. The `PushoverService` is provided by the bundled **Pushover** extension and is only available via the DI container when that extension is installed and enabled (it registers its own container definition). The `EmailService` is part of core.

### Pushover Notification on Object Created

```php
use TotalCMS\Bundled\Pushover\PushoverService;

public function boot(ExtensionContext $context): void
{
    $pushover = $context->get(PushoverService::class);

    $context->addEventListener('object.created', function (array $payload) use ($pushover): void {
        $pushover->send(
            message: "New {$payload['collection']} object: {$payload['id']}",
            title: 'Content Created',
        );
    });
}
```

### Email Notification on Import Completed

```php
use TotalCMS\Domain\Mailer\Service\EmailService;

public function boot(ExtensionContext $context): void
{
    $mailer = $context->get(EmailService::class);

    $context->addEventListener('import.completed', function (array $payload) use ($mailer): void {
        $mailer->sendEmail('import-notification', [
            'collection' => $payload['collection'],
            'count'      => $payload['count'],
        ]);
    });
}
```

These listeners run after the core operation completes. If sending fails, the exception is caught and logged without affecting the content operation.
