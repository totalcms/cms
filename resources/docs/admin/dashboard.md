---
title: "Admin Dashboard Overview"
description: "Find your way around the Total CMS admin: the sidebar, the dashboard home and its alerts, Quick Actions and Recent Activity, editing in collection tables, and the Dashboard settings."
related:
  - operations/activity
  - notifications/mailer
  - admin/whitelabel
  - extensions/extension-points
---

# Admin Dashboard Overview

The admin is where you manage content, structure and the site's settings. This page covers the parts every page shares, the dashboard home, and the settings that shape both.

## The Sidebar

The sidebar down the left side links to each part of the admin: Collections, Schemas, Data Views, Site Builder, Mailer, Automations, Twig Playground, anything extensions add, then Utils, Extensions, Settings and Docs. Each person only sees the pages their [access group](docs/auth/access-groups) and edition allow.

Press **⌘⇧O** (**Ctrl+Shift+O** on Windows and Linux) for **Quick Navigation**, a search box that jumps to any admin page, collection, Site Builder page or setting.

### The More menu

The sidebar has room for a dozen icons before it gets crowded, and extensions can add more. Under **Settings → Dashboard → More Menu**, check any sidebar items you rarely use. They leave the sidebar and move into a **More** (⋮) menu at its bottom, where they stay one click away rather than disappearing. Items added by extensions are listed there too. Nothing is locked: if you never use Automations or Site Builder, tuck them away; they are still reachable from More and from Quick Navigation.

## The Dashboard Home

A new site, with three collections or fewer, opens on a welcome page that links to project setup and the starters. After that, the home shows the sections below.

### Alerts and Needs Attention

Admins see alerts on the home whenever something needs them. Each one links to where you fix it. When nothing needs attention, neither the banners nor the card appear.

**Banners across the top** are for things that are broken, or that will lock you out:

| Alert | What to do |
|---|---|
| The license is invalid or expired | Open the License Manager |
| **Email is not set up** | No **From** address is set under [Settings → SMTP](docs/notifications/mailer#setting-up-smtp), so no email can be sent — including the password reset email, which would leave a forgotten admin password with no way back in. |
| The job queue is stalled | Schedule `tcms jobs:process`, or the [`/cron/jobs` URL](docs/operations/cron-urls) |
| An extension failed to load | Open Extensions |
| PHP's upload limits are below one upload chunk | Raise `post_max_size` and `upload_max_filesize` to at least 6 MB |
| You are simulating another edition | Turn simulation off under Settings → License |

**The Needs Attention card**, at the top of the right-hand column, lists things worth a look where nothing is down:

| Item | What to do |
|---|---|
| A Total CMS update is available | Open the update page |
| A trial has 30 days or less left | Open the License Manager |
| A trial is not registered | Register it to get reminders before it expires |
| An automation failed on its last run | Open Automations |
| Emails could not be sent since yesterday | Opens the matching `mailer.sent` rows in the [Activity Log](docs/operations/activity), each with its reason |

### Statistics

Counts of collections, objects, schemas, Site Builder templates and queued jobs.

### Quick Actions

A row of shortcuts under the statistics. Choose them under **Settings → Dashboard → Quick Actions**: add, remove and drag actions from a fixed list, and they show in that order. Remove them all to hide the row.

| Action | Opens |
|---|---|
| New Collection, New Schema | The new collection or schema form |
| New Page, New Template | Site Builder's new page or new template form |
| New Data View, New Email, New Automation | The new view, email template or automation |
| Twig Playground, Documentation, Settings | Those pages |
| Activity Log, Log Analyzer, Job Queue Manager, Cache Manager | Those utilities |

Until the setting is saved, the row shows New Collection, New Schema, New Template, Log Analyzer, Job Queue Manager and Cache Manager. Each person only sees the actions their access group and edition allow: the utilities, Settings and New Automation are for admins.

### Collections and Recent Activity

At the top of the main column, admins see **Recent Activity**: a short table of the 25 newest rows of the [Activity Log](docs/operations/activity) — when, what happened, to what, and who — that scrolls within a fixed height. Hover a row for its summary. An object that still exists links to its edit page; **View all activity** opens the full log with its filters. Only admins see it, because the log names users, API keys and email recipients.

Below it are the most recently changed collections, then any collections that are still empty, with a button to add their first object.

### The sidebar column

For admins, the right-hand column shows the [Needs Attention](#alerts-and-needs-attention) card when it has anything in it, then:

- **Development Mode** — a switch that turns off caching while you build.
- **Automations** — each enabled automation, its trigger, and how its last run went.
- **System Information** — the Total CMS version, PHP version, environment, memory limit and cache backends.

Extensions can add their own widgets to the main column or this one; see [dashboard widgets](docs/extensions/extension-points#dashboard-widgets).

A [white-label](docs/admin/whitelabel) install can replace the welcome message at the top of the home with its own.

## Editing in the Table

Simple values can be changed without opening the object. Hover a cell and a
pencil appears at its right edge; click it and the cell becomes that one
field, with a check mark to save and a cross to cancel beside it. Enter saves
from a single-line input, Escape cancels, and the cell shows the new value as
soon as the save lands. While a cell is open, no other cell offers a pencil.
Nothing else on the page reloads, and the value is saved exactly as the
object form would save it: a toggle stays a boolean, a list stays a list,
rich text stays HTML.

The pencil appears on text, textarea, number, range, price, toggle,
checkbox, select, radio, multiselect, checklist, date, datetime, time, url,
email, phone, color, list and styled text fields. Identity fields (`id`,
slugs), passwords and secrets, readonly fields such as the created and
updated timestamps, and composites (images, galleries, files, depots, decks,
cards, code) do not offer it: open the object to edit those. A field the
current user's access group cannot change is refused the same way it is on
the object form.

Two things decide whether a user gets the pencil at all. **Settings →
Dashboard → Inline Editing** is the site-wide master switch: turn it off and
the pencil disappears from every collection table for everyone, super admins
included, and an inline save is refused even if something asks for one
directly. With it on, the **Inline Editing** permission on the user's access
group decides who may use it, and the user must still have `update` rights on
that collection. Groups saved before the permission existed keep inline
editing, so nothing changes until you turn something off.

## Dashboard Settings

**Settings → Dashboard** holds the rest of the admin's preferences. They apply to everyone on the site.

| Setting | Default | What it does |
|---|---|---|
| Admin Dashboard Browser Title | Total CMS Admin | The browser tab title; left at the default, it becomes "{Site Name} Admin" once a site name is set |
| Dashboard Table Pagination | 50 | Rows per page in collection tables; `0` shows every row on one page |
| Confirmation Countdown | 2 | Seconds the confirm button in a delete or reset dialog stays disabled, so the message gets read; `0` turns it off |
| Dashboard Accent Color | orange | The admin's accent color |
| Keep ID on Duplicate Object | off | Keep the original ID when duplicating an object, instead of clearing it so the ID rule fills it in |
| Full Width Object Forms | off | Let object forms fill the page instead of stopping at 800px |
| Inline Editing | on | The master switch for [editing in the table](#editing-in-the-table) |
| Quick Actions | six actions | The dashboard home's [Quick Actions](#quick-actions), in order |
| More Menu | none | Sidebar items moved into the [More menu](#the-more-menu) |
