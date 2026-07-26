---
title: "Deck Item Label"
description: "Customize deck item labels in the Total CMS admin using template syntax with field references, minItems, maxItems, and modalSize settings. Includes the on-disk shape of deck data and how to define a deck in a schema."
---

# How a Deck Is Stored

Read this first if you are writing deck data by hand — in a JumpStart file, through
the API, or from a script. The admin handles all of this for you; these rules only
surface when you author the JSON yourself.

A deck is **a dictionary of named items, not a list**. Each key names one item, and
that key must also appear as the item's `id`:

```json
{
	"myDeck": {
		"first_item":  { "id": "first_item",  "name": "First",  "qty": 1 },
		"second_item": { "id": "second_item", "name": "Second", "qty": 2 }
	}
}
```

Three rules the runtime enforces:

1. **A dictionary, never an array.** `[{...}, {...}]` is rejected — items are keyed so
   templates can reach a specific one by name.
2. **Keys may contain only letters, numbers, and underscores.** No hyphens (see
   *Important Notes* below).
3. **An item's `id` must equal its key.** Omitting `id` is allowed; contradicting the
   key is not.

In Twig, iterate the deck or address an item by name:

```twig
{% for item in object.myDeck %}{{ item.name }}{% endfor %}

{{ object.myDeck.first_item.name }}
```

## Defining a Deck in a Schema

A deck points at a **second schema** describing one item, via `schemaref` plus a
matching `patternProperties` entry:

```json
"myDeck": {
	"$ref": "https://www.totalcms.co/schemas/properties/deck.json",
	"field": "deck",
	"label": "My Deck",
	"schemaref": "https://www.totalcms.co/schemas/custom/my-item.json",
	"settings": { "deckItemLabel": "${name}" },
	"patternProperties": {
		"^[a-z0-9][a-z0-9_]*$": {
			"$ref": "https://www.totalcms.co/schemas/custom/my-item.json"
		}
	}
}
```

The item schema is an ordinary object schema, and — like every Total CMS schema — it
**must define an `id` property**, not merely list `id` in `required`. Leaving it out
produces a validation error that points at your data (`The required properties (id)
are missing`) when the real problem is the schema:

```json
{
	"$schema": "https://json-schema.org/draft/2020-12/schema",
	"$id": "https://www.totalcms.co/schemas/custom/my-item.json",
	"id": "my-item",
	"type": "object",
	"title": "My Item",
	"properties": {
		"id":   { "$ref": "https://www.totalcms.co/schemas/properties/slug.json", "field": "id", "label": "Key" },
		"name": { "type": "string", "field": "text", "label": "Name" }
	},
	"required": ["id"],
	"index": ["id", "name"]
}
```

Use `"field": "deckTable"` instead of `"deck"` to render the same data as an editable
table in the admin — a good fit for rows of short, uniform values.

---

# Deck Item Label

The `deckItemLabel` setting controls how deck items are labeled in the admin interface. It uses the same template syntax as the `autogen` setting (see [ID Autogen](docs/fields/id) documentation), but displays raw values without slugification.

**Default:** `${id}` (displays the item's ID)

### Basic Usage

```json
{
	"deckItemLabel" : "${rating} - ${name}"
}
```

### Supported Placeholders

- **Field values:** `${fieldName}` - Any field from the deck item schema
- **Multiple fields:** `${id} - ${title}` - Combine multiple fields with separators
- **Nested values (dot notation):** `${field.key}` - Reach into a composite field's value:
  - `${card.title}` - a sub-field of a `card` field
  - `${headline.es}` - a single locale of a `localizedtext` / `localizedstyledtext` field
  - `${card.headline.es}` - both at once (a localized sub-field inside a card)
  - A bare `${card}` or `${headline}` (a composite value with no sub-key) resolves to nothing rather than a raw dump — point at the part you want.
- **Dynamic values (new items only):**
  - `${uid}` - Random unique ID
  - `${uuid}` - Full UUID
  - `${now}` - Current timestamp
  - `${currentyear}`, `${currentmonth}`, `${currentday}` - Date components

### Examples

**Simple ID display:**
```json
"deckItemLabel": "${id}"
```

**Rating and name:**
```json
"deckItemLabel": "${rating} ★ - ${name}"
```

### Important Notes

> **Note:** `${oid}` is not supported for deck items. Use `${uuid}` or `${uid}` for auto-generated deck item IDs instead.

- **No slugification:** Values are displayed as-is without URL-safe transformation. If a field contains "The Big Red Fox", the label will show exactly that.
- **Twig compatibility:** Deck item IDs may contain only letters, numbers, and underscores — **hyphens are not allowed**. The admin converts hyphens to underscores as you type, so this only bites when you write deck data by hand (JumpStart files, the API, imports), where a hyphenated key is rejected. The rule exists so items are readable with dot notation in Twig (`mydeck.item_id` rather than `mydeck['item-id']`).
- **SVG support:** If a field contains SVG code, it will be displayed as a small icon in the label.
- **Long text:** Labels automatically truncate with ellipsis (...) if content is too long.

---

# Min/Max Item Count

The `minItems` and `maxItems` settings control how many items a deck field can contain.

| Setting | Default | Description |
|---------|---------|-------------|
| `minItems` | `0` | Minimum number of items required. Validated on form submission. |
| `maxItems` | `-1` | Maximum number of items allowed. `-1` means unlimited. When reached, the add and duplicate buttons are disabled. |

### Basic Usage

```json
{
	"minItems": 1,
	"maxItems": 5
}
```

### Examples

**Require at least one item:**
```json
"minItems": 1
```
Validation will fail with "Please add at least 1 items" if the deck is empty on submit.

**Limit to a maximum of 3 items:**
```json
"maxItems": 3
```
The add and duplicate buttons will be disabled once 3 items exist. Removing an item re-enables them.

**Exact count (e.g., exactly 3 items):**
```json
{
	"minItems": 3,
	"maxItems": 3
}
```

---

# Modal Size

The `modalSize` setting controls how large the deck item edit modal opens in the admin.

| Value | Description |
|-------|-------------|
| `small` *(default)* | Narrow modal (max width ~75ch). Best for simple item schemas with a few short fields. |
| `medium` | Standard `cms-modal` sizing — 90% width up to 1000px, 85vh tall up to 1000px. |
| `large` | Roomy modal — up to 1400px wide and 1400px tall. Useful when deck items contain rich-text or many fields. |

### Basic Usage

```json
{
	"modalSize": "medium"
}
```
