# Deck Item Label

The `deckItemLabel` setting controls how deck items are labeled in the admin interface. It uses the same template syntax as the `autogen` setting (see [ID Autogen](docs/property-settings/id) documentation), but displays raw values without slugification.

**Default:** `${id}` (displays the item's ID)

### Basic Usage

```json
{
	"reviews": {
		"$ref": "https://www.totalcms.co/schemas/properties/deck.json",
		"label": "Customer Reviews",
		"settings": {
			"deckref": "https://www.totalcms.co/schemas/custom/review.json",
			"deckItemLabel": "${rating} - ${name}"
		}
	}
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
