---
title: Extension Automations
description: Ship automations from an extension with addAutomation().
related:
  - automations/overview
  - extensions/extension-points
  - automations/handlers
---

# Extension Automations

Extensions can contribute automations from code, so a feature that needs a recurring job or an event reaction can ship it instead of asking the operator to author one by hand.

## Registering one

Call `addAutomation()` from your extension's `register()`:

```php
public function register(ExtensionContext $context): void
{
    $context->addAutomation(
        id: 'prune-drafts',
        label: 'Prune stale drafts',
        triggers: [
            ['type' => 'schedule', 'cron' => '0 3 * * *'],
            // ['type' => 'event', 'event' => 'object.created', 'collection' => 'members'],
        ],
        handler: function (\TotalCMS\Domain\Automation\Data\AutomationContext $ctx): array {
            $ctx->logger->info('[acme/starter] prune-drafts tick');
            // ... work via $ctx->objectFetcher / $ctx->objectRemover ...

            return ['pruned' => 0];
        },
    );
}
```

The handler receives the same [`AutomationContext`](handlers.md#the-automationcontext) as a file-based automation.

## How they differ from file automations

- **Code, not content.** The handler is a closure held in memory, rebuilt from your extension's code each request — nothing is written to the `automations` collection.
- **Read-only in the admin.** Operators can run or disable an extension automation, but they can't edit the closure (it lives in your code). They appear in the automations list tagged as extension-owned.
- **Schedule + event only.** Extension automations participate in schedule and event dispatch. For HTTP, register a [public route](../extensions/extension-points.md#public-routes) instead of a webhook trigger — an extension automation's id (`vendor/name:id`) isn't a URL path.

## The capability toggle

Registering an automation surfaces the auto-detected **`automations`** capability for your extension. Operators can switch it off from the extension's permissions — that hides and stops dispatching all of the extension's automations without uninstalling it. It's also flagged on the pre-enable review screen, since automations run code on a schedule or content events.

See [Extension Points → Automations](../extensions/extension-points.md#automations) for the reference, and the [extension starter](https://github.com/totalcms/extension-starter) for a working example.
