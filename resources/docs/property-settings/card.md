---
title: "Card Fields"
description: "Group related fields into a single nested object using the card field type — a single-instance deck whose shape is defined by another schema via schemaref."
---

# Card Fields

A **card** is a single-instance deck. It renders the properties of another schema as inline sub-fields and stores the collected values as a nested object on the parent. Use it to group related settings (sitemap config, auth options, integration credentials, etc.) without the deck UX of add/remove/duplicate.

The card's shape is defined by a separate schema, referenced via `schemaref`. This keeps the nested structure reusable across collections and makes the parent schema easy to read.

## How It Differs From a Deck

| | Deck | Card |
|---|---|---|
| Cardinality | Many items | Exactly one |
| UI | Modal dialog with add/remove/duplicate | Inline sub-fields, no buttons |
| Stored as | Object keyed by item IDs | Single nested object keyed by property name |
| Schema | `schemaref` defines item shape | `schemaref` defines the card's shape |

If you want a single named-object grouping, use a card. If you want a list of named objects, use a deck.

## Basic Usage

```json
{
	"sitemap": {
		"$ref"      : "https://www.totalcms.co/schemas/properties/card.json",
		"field"     : "card",
		"label"     : "Sitemap Settings",
		"schemaref" : "https://www.totalcms.co/schemas/sitemap-meta.json"
	}
}
```

The referenced schema (`sitemap-meta.json` here) defines the sub-fields the card will render. Its properties become the card's properties at save time.

## Storage Format

A card stores as a single nested object keyed by sub-property name:

```json
{
	"sitemap": {
		"enabled"   : true,
		"frequency" : "weekly",
		"priority"  : 0.7
	}
}
```

In Twig, access values with dot notation:

```twig
{% if object.sitemap.enabled %}
	<priority>{{ object.sitemap.priority }}</priority>
{% endif %}
```

## Settings

### `schemaref`

**Required.** URL of the schema that defines the card's sub-fields.

```json
{
	"schemaref" : "https://www.totalcms.co/schemas/sitemap-meta.json"
}
```

> **Legacy alias:** `deckref` is still accepted as an alias for `schemaref` and will continue to work indefinitely. New schemas should use `schemaref`.

### Sub-Field Behavior

Sub-fields are rendered using the referenced schema's `properties`, `required`, and `formgrid`. A few rules apply:

- The referenced schema's `id` field is **skipped** — a card is a single object with no meaningful identifier of its own.
- Sub-field `default` values are applied when the card has no value for that property.
- Sub-field `settings` are run through the same preset pipeline as top-level fields, including named presets and type-default presets.
- The referenced schema's `formgrid` is honored for sub-field layout. Any sub-field not listed in `formgrid` is appended automatically.

## Property Restrictions

Card values must be basic types or simple property schemas. The following are **not** allowed inside a card:

- `gallery`, `depot`, `folder` (plurality models that don't fit the single-value-per-child shape)
- Other decks (cards inside decks are fine; decks inside cards are not)

Allowed property types include `string`, `number`, `boolean`, `card` (nested), `color`, `date`, `email`, **`file`**, **`image`**, `list`, `password`, `phone`, `slug`, `svg`, `time`, and `url`.

### Referencing nested images and files in Twig

Images and files stored inside a card are addressed by **dot-notation** in the `property` option of the standard media/render macros:

```twig
{# URL only #}
{{ cms.media.imagePath('post-1', {w: 800}, {property: 'mycard.image'}) }}

{# Full <img> tag #}
{{ cms.render.image('post-1', {w: 800}, {property: 'mycard.image'}) }}

{# File download URL #}
{{ cms.media.download('post-1', {property: 'mycard.attachment'}) }}
```

The first segment is the card field's name on the parent object; subsequent segments walk down into the card. The Image Builder dialog (opened from the admin form) emits the correct dotted-property macro automatically. See [cms.media → Nested images](docs/twig/media#nested-images-cards-and-decks) for full reference.

## Complete Example

A card that groups sitemap configuration into a single nested object:

**Parent schema:**
```json
{
	"sitemap": {
		"$ref"      : "https://www.totalcms.co/schemas/properties/card.json",
		"field"     : "card",
		"label"     : "Sitemap Settings",
		"schemaref" : "https://www.totalcms.co/schemas/sitemap-meta.json"
	}
}
```

**Referenced schema (`sitemap-meta.json`):**
```json
{
	"properties": {
		"enabled": {
			"type"    : "boolean",
			"field"   : "checkbox",
			"label"   : "Include in Sitemap",
			"default" : true
		},
		"frequency": {
			"type"    : "string",
			"field"   : "select",
			"label"   : "Change Frequency",
			"options" : ["always", "hourly", "daily", "weekly", "monthly", "yearly", "never"],
			"default" : "weekly"
		},
		"priority": {
			"type"    : "number",
			"field"   : "number",
			"label"   : "Priority",
			"default" : 0.5
		}
	}
}
```

**Resulting object value:**
```json
{
	"sitemap": {
		"enabled"   : true,
		"frequency" : "weekly",
		"priority"  : 0.7
	}
}
```

## Common Use Cases

- **Grouped settings** — sitemap, SEO, OpenGraph, schema.org metadata
- **Integration credentials** — paired API keys and endpoints (combine with `secret` fields for the sensitive parts)
- **Feature toggles** — a single `features` card with checkbox sub-fields
- **Address blocks** — street, city, state, zip as one nested object
