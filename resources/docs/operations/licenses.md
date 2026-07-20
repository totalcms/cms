---
title: "Licenses"
description: "Compare Total CMS Lite, Standard, and Pro license editions with per-domain pricing, feature breakdowns, free trial access, and edition simulation."
---

# Licenses

Total CMS is licensed on a per domain basis.
There are 3 different pricing tiers that will
control what features you will have access to.

## Lite

Basic collection schemas available to all editions:

* code
* color
* date
* email
* file
* feed
* gallery
* image
* number
* styledtext
* svg
* text
* toggle
* url

## Standard

Everything in Lite including:

**Schemas:**
* blog
* depot

**Features:**
* Image watermarks
* Mailer form actions
* QR codes
* Templates
* Access groups

## Pro

Everything in Standard including:

**Schemas:**
* Custom schemas

**Features:**
* Text watermarks
* Webhook form actions
* External REST API
* Barcodes
* Whitelabel templates
* API keys

## Free Trial

You can install Total CMS and use it for 45 days for free on any domain.
Trial licenses have full Pro-level access.

## Development License

Development licenses have full Pro-level access.

## Offline Licensing

For deployments that cannot (or must not) reach the internet — air-gapped networks, classified environments, strict data-sovereignty setups — Total CMS supports a fully offline license. Available for Pro licenses; [contact us](https://totalcms.co/support) to arrange one for a specific deployment.

**How it works:** we generate a cryptographically signed license file for your domain, which you install at:

```
tcms-data/.system/{domain}-offline-license.key
```

The file is checked before any online validation, so with it in place there is **no validation callback, no periodic check-in, and no outbound connectivity of any kind**. The signature is verified locally against a public key that ships with Total CMS.

Installing an offline license also **disables error monitoring automatically** — a network-isolated deployment never attempts an outbound call, regardless of the settings toggle. See [Error Monitoring](docs/operations/security#error-monitoring).

Updates for offline deployments are downloaded separately, transferred across the network boundary by whatever process your environment permits, and installed manually.

## Edition Simulation

During a trial or with a development license, you can simulate lower editions
(Lite or Standard) via the admin settings. This allows you to test how your
site will behave with different license tiers before purchasing.

**Important:** If you create data using features from a higher edition and
later purchase a lower edition, your data will not be deleted. However, you
will lose access to that data until you upgrade to an edition that supports it.
