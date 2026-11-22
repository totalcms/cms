---
title: "Backups"
description: "Total CMS keeps a snapshot history for every object: its state before each save and its final state on delete, and the images and files behind it when you ask. See what changed and restore it from the admin or the CLI."
since: "3.6.0"
related:
  - operations/sync
  - extensions/cli
---

# Backups

Every time an object is saved, Total CMS keeps the version it just replaced. Every time one is deleted, it keeps the final state. Undoing a bad edit — or getting a record back that someone deleted — is a click in the admin or a single command, and no configuration is needed to turn it on.

This is a **record history**, not a site backup. Out of the box only the object's data is kept, not its uploaded images or files, because every kept file is a full copy's worth of disk. You can turn that on for images, files, galleries and depots: see [Keeping Images and Files](#keeping-images-and-files). Keep taking real backups of `tcms-data/`.

## What Gets Kept

| Event | Snapshot |
|-------|----------|
| An object is **saved** | The state *before* the save |
| An object is **deleted** | The final state, as it was |
| A **sync** overwrites a schema, collection or object | The file that was about to be replaced (see [Sync](docs/operations/sync#automatic-backups)) |

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

Tune either under **Settings → Backups** in the admin — or switch the whole thing off there. The same keys can be set in `config/tcms.php` instead, which wins over the admin and is the right place when several installs share one data folder:

```php
$settings['backups'] = [
	'enable'        => true,
	'keep'          => 10,
	'maxAgeDays'    => 30,    // 0 disables the age rule
	'keepImages'    => 0,     // earlier images kept per image field
	'keepFiles'     => 0,     // earlier files kept per file field
	'trash'         => false, // keep what is removed from a gallery or depot
	'maxFileSizeMB' => 100,   // never keep a larger file; 0 for no limit
];
```

A busy editorial site might raise `keep`; a site on tight shared hosting might lower `maxAgeDays`. The footprint is small either way — a snapshot is the size of the record's JSON, and most records are saved a handful of times in their life.

## Keeping Images and Files

A snapshot holds the record, and the record only names its image or file. If the image was replaced since, the snapshot names a file that is gone. Four settings fix that. All of them are off until you turn them on, under **Settings → Backups** or in `config/tcms.php`.

| Setting | Default | What it does |
|---------|---------|--------------|
| `keepImages` | `0` | Each `image` field keeps this many replaced or removed images |
| `keepFiles` | `0` | Each `file` field keeps this many replaced or removed files |
| `trash` | `false` | Pictures removed from a `gallery` and files removed from a `depot` are kept until they are too old |
| `maxFileSizeMB` | `100` | A file larger than this is never kept. `0` means no limit |

### Earlier images and files

`keepImages` and `keepFiles` count per field, per object. With `keepImages` at `2`, every image field of every object keeps the two images it held before the current one.

A field inside a `card` or a `deck` item counts as its own field. So does a video's poster, which is an image. One team member's photo never pushes out another's.

A file is kept at the moment it would otherwise be deleted:

- an upload **replaces** it,
- an editor **removes** it, or clears the field,
- a deck item that holds it is **removed**,
- its object is **deleted**.

### The trash

A gallery or a depot has no "earlier version": files are added and removed one at a time. So `trash` has no count. Everything removed from a gallery or a depot is kept, in the folder it was in, until it is older than `maxAgeDays`. Then it is deleted. If `maxAgeDays` is `0`, the trash is emptied after 30 days: it always empties.

The cost is disk space. Deleting a 2 GB depot with the trash on holds 2 GB for `maxAgeDays` days. That is why the trash has its own switch.

### What it costs

A file is moved, not copied, so keeping it takes no extra space until the next upload arrives. What it does cost is the space the old file would have given back: a kept file is the full original.

`maxFileSizeMB` is the guard. A file larger than it is deleted when it is replaced or removed, exactly as before, whichever of the three settings would have kept it. Lower it on tight hosting. Set it to `0` only if you know how large your files get.

`maxAgeDays` applies to everything kept: an earlier image or file older than the limit is deleted, whatever the count. The job runner checks this for every object once a day (`tcms jobs:process`, or the [cron URL](docs/operations/cron-urls)). A site with no cron is still covered: the same check runs on a day when some file is kept.

Switching **Keep Object History** off stops all of this as well, because without a snapshot nothing could restore the files.

### Where they are

Kept files sit beside the object's snapshots, one dated folder per event, with the field's own folders inside it:

```
tcms-data/.system/backups/objects/{collection}/{id}/files/{property}/{YYYYMMDD-HHMMSS}/{filename}
tcms-data/.system/backups/objects/{collection}/{id}/files/team/{YYYYMMDD-HHMMSS}/alice/photo/{filename}
tcms-data/.system/backups/objects/{collection}/{id}/trash/{property}/{YYYYMMDD-HHMMSS}/{folder}/{filename}
```

**Not kept:** files stored by the S3 Uploads extension. They live in your bucket; use the bucket's own versioning for those.

## Restoring in the Admin

**Utilities → Backups** shows an object's history and puts a version back. There are three ways to it:

- On an object's edit form, open the **⋯** menu and choose **Restore from Backup**. It opens that object's history.
- On a collection, open the **⋯** menu and choose **Deleted Objects**. It lists the objects of that collection that were deleted and still have snapshots.
- Open **Utilities → Backups** and pick a collection. Add an object ID to open one object's history.

### An object's history

The history is newest first, under a heading for each day. Each row is one change: when it happened, who made it, and which fields it touched. **Compare** opens the change in a dialog and shows each field's value before and after, side by side. Long text shows both versions in full. A password only says that it changed; its value is never shown.

"Who" comes from the [Activity Log](docs/operations/activity). It reads *unknown* when the log is off, or when the entry is older than the log keeps.

Below the history is a list of the files kept for the object: earlier images and files, and anything in the trash. It answers "where did my file go".

### Putting a version back

A snapshot holds the object as it was *before* a change. So **Restore** on a row puts the object back to how it was before that change.

- **Restore** restores the whole object.
- **Restore this field**, beside a field in the Compare dialog, restores one field and leaves the rest of the object as it is now. Use it to get an old image back without losing newer text. Inside a deck, the unit is the whole deck.

Either one first shows what it will do: the fields that will change, the files that will come back, and the files that cannot come back because they were not kept. Nothing changes until you confirm.

The version you replace is saved first, so a restore can be undone: it is the newest row afterwards.

Restoring a user from the admin keeps the password that user has now. A snapshot holds the old one, and bringing it back would make an old password work again. (The CLI restores exactly what the snapshot holds.)

### Deleted objects

The list shows when each object was deleted, by whom, and how many snapshots and kept files it has. **Restore** brings the object back as it was when it was deleted. **History** opens its full history, so you can bring back an earlier version instead.

### Who can use it

Backups is a utility page, so access is granted per [access group](docs/auth/access-groups) like any other utility. Administrators always have it.

On top of that, a user sees and restores only what they may already edit:

- They need **update** on the collection. History shows old values of every field, so the right to read is not enough.
- Bringing back a deleted object also needs **create**.
- In a collection where members own their objects, a member sees only the history of their own. Its deleted objects are listed for administrators only.
- A restore is an ordinary save, so every rule that applies to a save applies to it.

## Listing Snapshots from the CLI

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

When the object has files kept, they are listed under the snapshots. The first column is the field, with the card child, deck item or depot folder the file sat in:

```
4 kept files (a restore puts back the one its snapshot names)

Field                  File            Set aside            Size
image                  hero.jpg        2026-09-20 14:30:22  412.0 KB
image                  old-banner.jpg  2026-09-19 09:15:00  388.4 KB
team/alice/photo       alice.jpg       2026-09-18 11:02:10  96.3 KB
downloads/Price Lists  2025.pdf        2026-09-18 10:40:51  1.2 MB    trash
```

You don't choose a file to restore. You restore a snapshot, and it brings back the file that snapshot names.

## Restoring from the CLI

```bash
# A specific snapshot, from the list above
tcms backup:restore blog my-post my-post-20260919-091500.json

# The newest one
tcms backup:restore blog my-post --latest

# Non-interactive (CI, scripts)
tcms backup:restore blog my-post --latest --force
```

A restore is an ordinary save. The snapshot is decoded and written through the same path the admin uses, so the collection index rebuilds, every listener fires, and — because the save itself keeps the state it replaced — **the version you just overwrote becomes the newest snapshot**. Undoing a restore is another restore, one entry back. It is never a one-way door.

Restoring a deleted object recreates it.

### What Happens to Images and Files

After the record is back, each file it names is made to match it. For an `image` or `file` field, top-level or inside a card or a deck item:

- **The file the snapshot names is already there.** Nothing moves. A file replaced by another of the same name is told apart by its size, so a corrected `brochure.pdf` uploaded over `brochure.pdf` restores correctly.
- **It is in the kept files.** It is put back, and the command says so. The file it displaces is kept in turn, even if the setting is `0`, so undoing the restore brings that one back as well.
- **It is nowhere.** The record is restored anyway and the command warns you: the field names a file that is not on disk. Upload it again in the admin. This is what every restore of a replaced image did before these settings existed, and what happens when the file was replaced before you turned them on.

```
Object 'my-post' restored in 'blog' from my-post-20260919-091500.json.
  image: hero.jpg restored
  team/alice/photo: alice.jpg restored
  download: price-list.pdf is missing
```

For a `gallery` or a `depot`, every picture or file the snapshot names that is not on disk is brought back from the trash, into the folder it was in. Files that are there now and that the snapshot does not name are left alone.

Restoring a snapshot that still has a deck item you removed since brings the item back with its images and files.

A snapshot from before an image was ever uploaded names no file. Restoring it sets the current image aside when `keepImages` is on, and leaves it on disk, unreferenced, when it is off.

Both commands take `--json` for machine-readable output.

## Where It Fits

- **Editing mistakes** — this feature. In the admin: **Restore from Backup** on the object's ⋯ menu. From a terminal: `backup:list`, pick the version, `backup:restore`.
- **A record deleted by mistake** — this feature. **Deleted Objects** on the collection's ⋯ menu, or `backup:restore`.
- **The wrong image or file uploaded over the right one** — this feature, once `keepImages` or `keepFiles` is above zero.
- **A gallery picture or a depot folder removed by mistake** — this feature, once `trash` is on.
- **A sync that replaced the wrong thing** — also this feature; sync's pre-overwrite snapshots land in the same tree. See [Sync](docs/operations/sync#automatic-backups).
- **A rolled-back application update** — see [Updates](docs/operations/updates); that path keeps the previous release's files, not content.
- **Disaster recovery** — back up `tcms-data/` with your host's tooling. Snapshots are inside it, so they come along.
