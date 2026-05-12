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

Fired after a collection's settings are updated.

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | Collection ID |

```php
$context->addEventListener('collection.updated', function (array $payload): void {
    // e.g., react to collection configuration changes
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

### `import.completed`

Fired after a batch import (CSV, JSON, or URL) finishes processing.

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

## Listener Isolation

Each listener is wrapped in a try/catch. If your listener throws:

1. The exception is logged to `logs/extensions.log`
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

Extensions can use events to trigger notifications automatically when content changes. The built-in `PushoverService` and `EmailService` are available via the DI container in the `boot()` phase.

### Pushover Notification on Object Created

```php
use TotalCMS\Domain\Notification\Service\PushoverService;

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
