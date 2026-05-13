---
title: "Auth & Access"
description: "User authentication, passkeys, access groups, and member-only content in Total CMS."
---

# Auth & Access

Total CMS ships a complete auth system: password login, passkey (WebAuthn) login, password reset, public registration, and access-group-based content gating. Operators log in to the admin through the same system that site members use for member-only content.

## Core pages

- **[Authentication](docs/auth/auth)** — Configure login, choose `loginWith` (email, id, or both), set up passkeys, enable public registration.
- **[Access Groups](docs/auth/access-groups)** — Tag users with groups; gate pages, collections, and Twig blocks by group membership.
- **[Password Reset](docs/auth/password-reset)** — Self-serve password reset emails.

## In templates

- **[Auth Twig](docs/twig/auth)** — `cms.auth.userLoggedIn`, `cms.auth.userData`, group checks, login/logout/register form builders.

## Common tasks

- **Add a login form to a page** — `cms.form.builder('auth', {login: true})` (see [Specialized Forms](docs/twig/forms/specialized)).
- **Add a registration form** — `cms.form.builder('members', {register: true})` (collection must be in `auth.publicRegistration` allow-list).
- **Gate a page by access group** — Check `cms.auth.userInGroup('subscribers')` in your template.
- **Reset forgotten passwords** — Add the password reset link to your login form, configure the mailer.

## Security notes

Public registration auto-logs users in. If you expose a registration form on a site with gated content, gate the access group new users land in carefully — bots will sign up and see anything that group can see. Use CAPTCHA, rate limiting, or email verification when registrants land in a group with sensitive access.
