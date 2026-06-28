---
title: "User Impersonation"
description: "Super-admins can temporarily browse and edit the site as another user to debug permission and access issues. All actions taken while impersonating are recorded in the access log."
updated: 2026-06-26
---

# User Impersonation

Super-admins can temporarily act as any other user — operator or front-end member — to reproduce and diagnose permission or access issues without sharing credentials.

## How It Works

Impersonation is a full session swap. While active, every request — reads **and writes** — executes as the target user. If you save, delete, or modify content while impersonating, those changes are attributed to the target, not to you. Use this feature carefully.

A fixed banner appears at the top of every page (admin and front-end) while impersonation is active. Click **Return to your account** in that banner to end the session immediately and return to your own account.

## Starting Impersonation

1. Open an auth-enabled collection (the `auth` operator collection or any public-registration member collection) in the admin.
2. Open the object for the user you want to impersonate.
3. Click the **⋯** collection-actions menu.
4. Select **Impersonate User**.

Total CMS redirects you automatically based on the target's type:

- **Operator targets** land in the admin dashboard.
- **Member targets** land on the front-end home page (the admin is gated to operators).

## Restrictions

- **Super-admin only.** Only super-admins can start an impersonation session.
- **Cannot impersonate super-admins.** The target must not be a super-admin.
- **Cannot impersonate yourself.**
- **No nesting.** You cannot start a new impersonation while one is already active.

## Ending Impersonation

Click **Return to your account** in the fixed banner. This works from any page — admin or front-end — and is the only way to end the session. The banner's return action is CSRF-protected.

## Audit Trail

Both the start and the stop of every impersonation session are recorded in the access log with the super-admin's identity, the target user, and the timestamp. Review the log in Admin → Access Log to audit impersonation activity.
