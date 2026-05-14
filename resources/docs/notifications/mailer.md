---
title: "Mailer"
description: "Configure and send transactional and notification emails from Total CMS."
---

# Mailer

> **Placeholder.** Full documentation for the Mailer is in progress.

Total CMS includes a built-in Mailer for sending transactional emails, form notifications, and admin alerts. The Mailer is accessible from the admin dashboard and integrates with form actions, password reset flows, and extension event listeners.

## What to document here

- Mailer configuration (SMTP, API providers, sender identities)
- Email templates and where they live
- Sending mail from Twig (`cms.mailer.send(...)`)
- Form-action integration for contact and notification forms
- Edition / license gating (which tiers include the Mailer)
- Admin UI walkthrough
- Access-group permissions (`mailer` capability — see [Access Groups](docs/auth/access-groups))

## Related

- [Pushover Notifications](docs/notifications/pushover)
- [Access Groups](docs/auth/access-groups) — controls who can use the Mailer
