---
title: "Deck Item Label"
description: "Customize deck item labels in the Total CMS admin using template syntax with field references, minItems, maxItems, and modalSize settings."
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
- **Twig compatibility:** Deck item IDs are automatically sanitized to use underscores instead of hyphens for Twig dot notation access (`mydeck.item_id`).
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
