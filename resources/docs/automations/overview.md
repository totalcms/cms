---
title: Automations
description: Run server-side PHP on a schedule, a webhook, or a content event.
related:
  - automations/triggers
  - automations/handlers
  - automations/webhooks
audience: intermediate
---

# Automations

Automations run a block of PHP you write — the **handler** — in response to a **trigger**: a schedule (cron), an incoming webhook, or a content event such as an object being created. They live entirely in the CMS; there's no separate worker service to deploy.

> Automations are a **Pro** feature.

## How it works

An automation is an object in the reserved `automations` collection. Each one has:

- **Triggers** — one or more of `schedule`, `webhook`, or `event` (see [Triggers](triggers.md)).
- **A handler** — the PHP that runs when a trigger fires (see [Writing Handlers](handlers.md)). The handler is stored in its own file alongside the object, so it's edited like any code field but never bloats the JSON.
- **An optional error mailer** — a [Mailer](../notifications/mailer.md) object used to email you when the handler throws in production.
- **A category** — groups automations in the admin sidebar (defaults to *Automations*).

Manage them under **Automations** in the admin (or `tcms` on the CLI). The editor shows the handler, the trigger list, a run history, a **Run now** button, and a non-blocking advisory if the handler reaches for high-risk calls (`exec`, `shell_exec`, `eval`, …).

## Scheduling: the process command

Webhook and event automations fire the moment their trigger happens. **Schedules** need a heartbeat — a single command run by cron:

```bash
* * * * * cd /path/to/site && php bin/tcms automations:process
```

Each tick `automations:process`:

1. Drains any queued asynchronous runs (async webhooks + event triggers).
2. Fires every schedule whose cron expression is now due.

Run it **every minute**; the command itself decides what's actually due, evaluating cron expressions in your site timezone (Settings → General). A single-flight lock keeps overlapping ticks from double-firing.

## Failure handling

Automations are environment-aware:

- **Development / preview** — a handler exception surfaces loudly so you can debug, and the automation keeps running.
- **Production** — the exception is contained. If an `errorMailerId` is set, you're emailed. After **5 consecutive failures** the automation is **auto-disabled** (so a broken handler can't fail — and email — forever). The admin shows a banner with a one-click **Re-enable** that clears the failure count.

Every run is recorded (status, duration, return value or exception) and visible in the editor's run history.

## Next steps

- [Triggers](triggers.md) — schedule, webhook, and event configuration
- [Writing Handlers](handlers.md) — the `AutomationContext` API
- [Webhooks](webhooks.md) — authentication, sync vs. async
- [Extension Automations](extensions.md) — ship automations from an extension
