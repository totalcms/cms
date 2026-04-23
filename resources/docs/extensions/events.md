---
title: "Extension Events"
description: "Reference for all content events that extensions can subscribe to in Total CMS."
since: "3.3.0"
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
