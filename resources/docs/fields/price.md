---
title: "Price Field"
description: "Locale-aware currency price field — live formatting as you type, a per-currency symbol and icon, configurable currency/decimals/locale, stored as a plain number."
updated: "2026-06-10"
---

# Price Field

The price field is a monetary input that formats the value as currency **while you type** — `100000` becomes `$100,000.00` — while still storing a plain **number** so you can do maths, sorting, and filtering on it. Currency, decimal places, and locale are configurable per field and default sensibly from your site locale.

It renders as a normal text input (so the currency symbol and thousands separators can be shown), with the currency **symbol** beside the value and a matching currency **icon** on the field.

## How it works

- Type digits and they're grouped and formatted live for the resolved locale (`100,000.00` in the US, `100.000,00` in Germany).
- The value is stored as a **number** — `100000` or `100000.5`, never a formatted string. Existing price values keep working unchanged.
- On mobile, the numeric keypad is used (digit-only when the field has no decimals).

## Settings

All optional — sensible defaults are derived automatically.

| Setting | Purpose | Default |
|---|---|---|
| `currency` | ISO 4217 code (`USD`, `EUR`, `GBP`, `JPY`, …) | Inferred from the resolved locale's region |
| `decimals` | Number of fraction digits (`0`, `2`, …) | The currency's standard (USD/EUR = 2, JPY = 0) |
| `locale` | BCP-47 tag (`en-US`, `de-DE`, …) drives the grouping/decimal style | Your site's default locale |

```json
{
	"field": "price",
	"settings": {
		"currency": "EUR",
		"locale": "de-DE",
		"decimals": 2
	}
}
```

The example above renders `100000` as `100.000,00` with a `€` symbol and the euro icon.

## Currency symbol and icon

- **Symbol** — the currency symbol (`$`, `€`, `£`, `¥`, `₹`, …) is shown next to the value, derived from the resolved currency.
- **Icon** — a matching field icon is selected automatically: `icon-dollar` for any `$` currency (USD, CAD, AUD, …), `icon-euro`, `icon-pound`, `icon-yen` (JPY **and** CNY). Currencies without a dedicated glyph use the generic currency icon.

To force a specific icon, set the `class` setting to one of `icon-dollar`, `icon-euro`, `icon-pound`, `icon-yen`, or `icon-currency` — an explicit choice wins over the automatic one:

```json
{
	"field": "price",
	"settings": { "class": "icon-pound" }
}
```

## Server-side coercion

The admin submits a clean number, but the field is also defensive on the server: a **formatted** price that arrives by any other route — a raw API post, a CSV/JSON import, a pasted `"$100,000.00"`, or a JavaScript-disabled browser — is parsed back to the correct float. Separator roles are inferred from the value (locale-free), so `"$100,000.00"`, `"100.000,00 €"`, `"¥100,000"`, and `"100000"` all store as the right number.
