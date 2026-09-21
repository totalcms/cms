---
title: "Backups"
description: "Total CMS keeps a snapshot history for every object: its state before each save and its final state on delete. List and restore them from the CLI."
since: "3.6.0"
related:
  - operations/sync
  - extensions/cli
---

# Backups

Every time an object is saved, Total CMS keeps the version it just replaced. Every time one is deleted, it keeps the final state. Undoing a bad edit — or getting a record back that someone deleted — is a single command, and no configuration is needed to turn it on.

This is a **record history**, not a site backup. Only the object's data is kept: never uploaded files or images, which would multiply disk use with every edit. Keep taking real backups of `tcms-data/`.

## What Gets Kept

| Event | Snapshot |
|-------|----------|
| An object is **saved** | The state *before* the save |
| An object is **deleted** | The final state, as it was |
| A **sync** overwrites a schema, collection or object | The file that was about to be replaced (see [Sync](sync#automatic-backups)) |

Creating an object writes nothing: its first state is recoverable from the first update's snapshot, and doubling writes on import-heavy sites buys nothing. Imports never write snapshots at all — the whole point of a bulk import is that it's bulk.

Snapshots live inside the data directory, so they stay with the content they protect and survive application updates:

```
tcms-data/.system/backups/objects/{collection}/{id}/{id}-{YYYYMMDD-HHMMSS}.json
```

Lifecycle snapshots are always JSON, whatever the collection's on-disk format — a collection converted to Markdown later does not strand its own history. Sync's snapshots keep the on-disk file, so they may be `.md`. Restore handles both.

## How Much Is Kept

Two rules run on every write, and either one can prune:

- **Count** — each object keeps its newest `keep` snapshots (default **10**).
- **Age** — anything older than `maxAgeDays` goes regardless of count (default **30**).

Count alone would let one busy afternoon of edits evict the version from last week — the one you actually wanted. Age alone would let a never-edited record hold a snapshot forever. Identical consecutive saves don't stack a duplicate.

Tune either under **Settings → Backups** in the admin — or switch the whole thing off there. The same three keys can be set in `config/tcms.php` instead, which wins over the admin and is the right place when several installs share one data folder:

```php
$settings['backups'] = [
	'enable'     => true,
	'keep'       => 10,
	'maxAgeDays' => 30,  // 0 disables the age rule
];
```

A busy editorial site might raise `keep`; a site on tight shared hosting might lower `maxAgeDays`. The footprint is small either way — a snapshot is the size of the record's JSON, and most records are saved a handful of times in their life.

## Listing Snapshots

```bash
tcms backup:list blog my-post
```

```
3 snapshots for blog/my-post

Snapshot                         Taken                Format   Size
my-post-20260920-143022.json     2026-09-20 14:30:22  json     2.1 KB   latest
my-post-20260919-091500.json     2026-09-19 09:15:00  json     2.0 KB
my-post-20260912-170045.md       2026-09-12 17:00:45  markdown 1.9 KB

Restore one with: tcms backup:restore blog my-post <snapshot> (or --latest)
```

The first column is what `backup:restore` takes. If the object has been deleted, the listing says so — its history is still there, and restoring brings it back.

## Restoring

```bash
# A specific snapshot, from the list above
tcms backup:restore blog my-post my-post-20260919-091500.json

# The newest one
tcms backup:restore blog my-post --latest

# Non-interactive (CI, scripts)
tcms backup:restore blog my-post --latest --force
```

A restore is an ordinary save. The snapshot is decoded and written through the same path the admin uses, so the collection index rebuilds, every listener fires, and — because the save itself keeps the state it replaced — **the version you just overwrote becomes the newest snapshot**. Undoing a restore is another restore, one entry back. It is never a one-way door.

Restoring a deleted object recreates it. The record reappears; any images or files it referenced are only there if they were never removed from disk, so check the object in the admin afterwards.

Both commands take `--json` for machine-readable output.

## Where It Fits

- **Editing mistakes** — this feature. `backup:list`, pick the version, `backup:restore`.
- **A sync that replaced the wrong thing** — also this feature; sync's pre-overwrite snapshots land in the same tree. See [Sync](sync#automatic-backups).
- **A rolled-back application update** — see [Updates](updates); that path keeps the previous release's files, not content.
- **Disaster recovery** — back up `tcms-data/` with your host's tooling. Snapshots are inside it, so they come along.
