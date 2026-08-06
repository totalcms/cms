---
title: "Sharing One Data Folder"
description: "Run several Total CMS installs against one tcms-data folder without serving stale content."
audience: advanced
updated: 2026-08-05
related:
  - operations/filesystem
  - operations/deployment
---

# Sharing One Data Folder

Several Total CMS installs can point at a single `tcms-data` folder — a staging
domain beside a live one, or a set of regional domains serving identical content.
Every install then reads the same collections, objects, and schemas.

Caching needs one setting changed for this to work.

## The problem

By default each install namespaces its cache entries by domain. That keeps
unrelated sites on one server from colliding in a shared APCu pool or Redis
instance. When installs share a data folder, though, it means they cache the same
content twice under different names — and a save through one install clears only
its own entries. The other installs keep serving what they cached until the entry
expires, which can be hours.

## The setting

In **Settings → Cache**, turn **Scope Cache by Domain** off.

The setting lives in `tcms-data/.system/settings.json`, which is itself shared, so
changing it in one install applies it to every install on that data folder.

With it off, cache entries are namespaced by the data folder instead of the
domain. Installs sharing a folder share one namespace, and a save in any of them
invalidates all of them. Unrelated installs with their own data folders stay
isolated, so a shared Redis instance is still safe.

## What stays separate

Three kinds of cached data remain per-domain whatever this setting says:

- **License validation.** Each domain is licensed separately.
- **Sessions.**
- **Password reset tokens.**

## Where the cache is stored

With domain scoping on, cache entries live in each install's `cache/` directory.
With it off, they move to `tcms-data/.system/cache` so the disk layer is shared
too. Nothing else moves — each install keeps compiling its own templates into its
own `cache/` directory, because those belong to the install's code rather than to
the shared content.

Clearing the cache from one install's admin clears the shared entries for every
install, plus that install's own compiled templates. If you edit a Site Builder
template stored in `tcms-data` through one install's admin, clear the cache on the
other installs as well so they recompile it.

## Turning it back on

Switching the setting either way clears the cache first, so nothing stale is left
behind under the old namespace. Expect the first few requests afterwards to be
slower while the cache refills.
