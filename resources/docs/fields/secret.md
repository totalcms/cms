---
title: "Secret Fields"
description: "Mask plaintext-stored sensitive values like API keys and tokens using the secret field type, with a built-in show/hide toggle and password-manager opt-out."
---

# Secret Fields

The **secret** field is a masked text input for storing sensitive values that need to be readable later — API keys, access tokens, webhook signing secrets, third-party credentials. It renders as a password input with a show/hide toggle so admins can reveal the value when needed.

Unlike the [password field](docs/fields/password), a secret is **not hashed** — it is stored as plain text. Use it only for values your application needs to read back; never use it for user passwords.

## When to Use Each

| Field | Stored As | Use For |
|---|---|---|
| `password` | Hashed | User account passwords |
| `secret` | Plain text, masked in UI | API keys, tokens, signing secrets |
| `text` | Plain text, visible | Non-sensitive strings |

## Basic Usage

```json
{
	"apiKey": {
		"type"  : "string",
		"field" : "secret",
		"label" : "API Key"
	}
}
```

The value is stored as a regular string property — no special storage format. The `secret` field type only affects how the value is rendered and edited in the admin form.

## Behavior

**Masking.** The field renders as `<input type="password">`, so the value is hidden by default.

**Show/hide toggle.** The form group's icon becomes a clickable toggle that flips the input between `password` and `text`. The container gets a `secret-visible` class while revealed, so you can style the toggle indicator if needed.

**Password-manager opt-out.** Secrets emit `data-1p-ignore` and `autocomplete="off"` so 1Password, browser autofill, and other managers leave them alone. This prevents accidental autofill of an API key into a user-password field on another page.

## Storage Format

Secrets are stored as plain string values:

```json
{
	"apiKey": "sk_live_abc123def456..."
}
```

In Twig, read them like any string:

```twig
{% set headers = { 'Authorization': 'Bearer ' ~ object.apiKey } %}
```

> **Security note:** Because secrets are stored in plain text, take care when exposing them. Avoid rendering secret values into public templates, RSS feeds, or sitemaps. Consider keeping secrets on settings schemas (admin-only) rather than on public-facing collections.

## Settings

The secret field accepts the standard text-input attributes: `placeholder`, `minlength`, `maxlength`, `pattern`, `required`, etc.

```json
{
	"webhookSecret": {
		"type"     : "string",
		"field"    : "secret",
		"label"    : "Webhook Signing Secret",
		"settings" : {
			"placeholder" : "whsec_...",
			"minlength"   : 32,
			"required"    : true
		}
	}
}
```

## Complete Example

A settings schema for a third-party integration:

```json
{
	"properties": {
		"endpoint": {
			"type"     : "string",
			"field"    : "url",
			"label"    : "API Endpoint"
		},
		"apiKey": {
			"type"     : "string",
			"field"    : "secret",
			"label"    : "API Key",
			"settings" : {
				"placeholder" : "sk_live_..."
			}
		},
		"webhookSecret": {
			"type"     : "string",
			"field"    : "secret",
			"label"    : "Webhook Signing Secret"
		}
	}
}
```

## Common Use Cases

- **Third-party API credentials** — Stripe, Mailchimp, OpenAI, etc.
- **Webhook signing secrets** — verifying inbound webhook payloads
- **Service account tokens** — long-lived bearer tokens for backend integrations
- **License keys** — commercial software activation strings
- **Encryption keys** — symmetric keys read at runtime by the application

## Pairing With Card Fields

Group an integration's endpoint and credentials into a single [card field](docs/fields/card) so the related settings stay together in the admin UI:

```json
{
	"stripe": {
		"$ref"      : "https://www.totalcms.co/schemas/properties/card.json",
		"field"     : "card",
		"label"     : "Stripe Integration",
		"schemaref" : "https://www.totalcms.co/schemas/custom/stripe-config.json"
	}
}
```

Where `stripe-config.json` contains a `publishableKey` (text), `secretKey` (secret), and `webhookSecret` (secret).
