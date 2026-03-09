---
title: "Deck Item Label"
description: "Customize deck item labels in the Total CMS admin using template syntax with field references, minItems, and maxItems constraints."
---

# Deck Item Label

The `deckItemLabel` setting controls how deck items are labeled in the admin interface. It uses the same template syntax as the `autogen` setting (see [ID Autogen](docs/property-settings/id) documentation), but displays raw values without slugification.

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
  - `${oid}` - Deck item count (1, 2, 3...)
  - `${oid-00000}` - Zero-padded deck item count (00001, 00002...)
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

**Sequential numbering:**
```json
"deckItemLabel": "Item ${oid}: ${title}"
```

**Zero-padded numbers:**
```json
"deckItemLabel": "Review ${oid-00000}"
```
Result: "Review 00001", "Review 00002", etc.

### Important Notes

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
