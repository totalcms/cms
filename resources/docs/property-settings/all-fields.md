---
title: "All Field Settings"
description: "Universal settings for all Total CMS field types including autogen, calc (computed fields), hidden fields, and conditional visibility."
---

# All Field Settings

These settings can be applied to **any field type**. They control universal behaviors like hiding fields and conditional visibility.

## Autogen

The `autogen` setting automatically generates a field's value from other fields using template variables. This works on any text-based field type (text, textarea, hidden, email, url, etc.).

When used on an **ID field**, the result is automatically slugified (lowercased, hyphens for spaces). When used on any other field, the result is kept as-is with no transformation.

```json
{
	"autogen": "${firstname} ${lastname}"
}
```

The value updates automatically whenever a referenced field changes.

### Template Variables

Use `${fieldname}` to reference any other property in the same form. There are also special built-in variables:

* **now** - Current timestamp in milliseconds
* **timestamp** - Current date/time in compact ISO format (e.g., 20230815T143056)
* **uuid** - UUID v4 (e.g., 550e8400-e29b-41d4-a716-446655440000)
* **uid** - Short random 7-character alphanumeric string
* **oid** - Object ID counter (increments per object in collection)
* **oid-00000** - Zero-padded Object ID
* **currentyear** - Full 4-digit year
* **currentyear2** - 2-digit year
* **currentmonth** - Zero-padded month (01-12)
* **currentday** - Zero-padded day (01-31)

### Examples

**Full name from first and last:**
```json
{
	"fullname": {
		"type": "string",
		"field": "text",
		"label": "Full Name",
		"settings": {
			"autogen": "${firstname} ${lastname}"
		}
	}
}
```
Generates: `John Smith` (not slugified since it's a text field)

**Display title with date:**
```json
{
	"displayTitle": {
		"type": "string",
		"field": "text",
		"label": "Display Title",
		"settings": {
			"autogen": "${title} (${currentyear})"
		}
	}
}
```
Generates: `My Article (2025)`

**Hidden computed field:**
```json
{
	"slug": {
		"type": "string",
		"field": "text",
		"label": "URL Slug",
		"settings": {
			"autogen": "${title}",
			"hide": true
		}
	}
}
```

**For ID-specific autogen features** (slugification, uniqueness checking, OID padding), see the [ID Settings](id.md) documentation.

## Calc (Computed Fields)

The `calc` setting evaluates a math expression to compute a field's value from other number fields. The field becomes **read-only** automatically since its value is derived.

```json
{
	"calc": "${price} * ${quantity}"
}
```

The value recalculates in real time whenever a referenced field changes.

### Field References

Use `${fieldname}` to reference any other property in the same form. For referencing fields inside deck items from the parent object, use dot notation: `${deckProperty.fieldName}`.

### Operators

* `+` Addition
* `-` Subtraction
* `*` Multiplication
* `/` Division (division by zero returns 0)
* `%` Modulo
* `()` Parentheses for grouping
* Unary `-` for negative numbers

### Functions

**Math functions:**
* **round(x)** - Round to nearest integer
* **floor(x)** - Round down
* **ceil(x)** - Round up
* **abs(x)** - Absolute value
* **min(a, b, ...)** - Minimum value
* **max(a, b, ...)** - Maximum value

**Aggregate functions** (for deck item fields):
* **sum(${deck.field})** - Sum all values
* **avg(${deck.field})** - Average of all values
* **count(${deck.field})** - Number of items
* **min(${deck.field})** - Minimum value
* **max(${deck.field})** - Maximum value

### Clamping with min/max

Use `min` and `max` settings alongside `calc` to clamp the computed result:

```json
{
	"settings": {
		"calc": "${subtotal} - ${discount}",
		"min": 0
	}
}
```

This ensures the result never drops below 0, even if the discount exceeds the subtotal.

### Examples

**Line item total (within a deck item):**
```json
{
	"lineTotal": {
		"type": "number",
		"field": "price",
		"label": "Line Total",
		"settings": {
			"calc": "${unitPrice} * ${quantity}"
		}
	}
}
```

**Subtotal from deck items:**
```json
{
	"subtotal": {
		"type": "number",
		"field": "price",
		"label": "Subtotal",
		"settings": {
			"calc": "sum(${items.lineTotal})"
		}
	}
}
```
Sums the `lineTotal` field from every item in the `items` deck property.

**Tax calculation:**
```json
{
	"tax": {
		"type": "number",
		"field": "price",
		"label": "Tax",
		"settings": {
			"calc": "round(sum(${items.lineTotal}) * ${taxRate} / 100)"
		}
	}
}
```

**Grand total with discount and tax:**
```json
{
	"grandTotal": {
		"type": "number",
		"field": "price",
		"label": "Grand Total",
		"settings": {
			"min": 0,
			"calc": "sum(${items.lineTotal}) - ${discount} + round((sum(${items.lineTotal}) - ${discount}) * ${taxRate} / 100)"
		}
	}
}
```

**Average rating from reviews:**
```json
{
	"avgRating": {
		"type": "number",
		"field": "number",
		"label": "Average Rating",
		"settings": {
			"calc": "round(avg(${reviews.rating}))"
		}
	}
}
```

**Item count:**
```json
{
	"totalItems": {
		"type": "number",
		"field": "number",
		"label": "Total Items",
		"settings": {
			"calc": "count(${items.id})"
		}
	}
}
```

### Order of Operations

When an object has both deck-item-level and object-level calc fields, they are processed in order:

1. **Deck item calcs first** - e.g., `lineTotal = ${unitPrice} * ${quantity}` within each item
2. **Object-level calcs second** - e.g., `grandTotal = sum(${items.lineTotal})` using already-computed values

On the frontend, this happens naturally through the event system. On the backend (API saves, imports), the same ordering is enforced explicitly.

### Important Notes

- **Read-only:** Calc fields are automatically set to read-only on the frontend. Users cannot manually override computed values.
- **Number fields:** Calc is designed for number fields (`number`, `price`). While it can be applied to other field types, the result is always numeric.
- **Missing fields:** Any referenced field that is missing or non-numeric is treated as `0`.
- **Backend evaluation:** Calc expressions are re-evaluated server-side on every object save, ensuring consistency even for API-driven updates.

## Hide Field

The `hide` setting allows you to completely hide a field from the admin form while still storing its data. This is useful for fields that should be managed programmatically or set via defaults rather than through the admin interface.

```json
{
	"hide": true
}
```

When `hide` is set to `true`, the field will have the `cms-hide` CSS class added, which hides it from view.

### Common Use Cases

**System-managed fields:**
```json
{
	"processedAt": {
		"type": "string",
		"field": "datetime",
		"label": "Processed At",
		"settings": {
			"hide": true
		}
	}
}
```

**Fields with autogen values that shouldn't be edited:**
```json
{
	"slug": {
		"type": "string",
		"field": "text",
		"label": "URL Slug",
		"settings": {
			"autogen": "${title}",
			"hide": true
		}
	}
}
```

**Metadata fields:**
```json
{
	"version": {
		"type": "number",
		"field": "number",
		"label": "Version",
		"default": 1,
		"settings": {
			"hide": true
		}
	}
}
```

### Important Notes

- **Data Storage:** Hidden fields still store data in the object. They are just not visible in the admin form.
- **Default Values:** Hidden fields typically should have a `default` value or be populated programmatically.
- **CSS Class:** The field receives the `cms-hide` class. You can customize visibility with CSS if needed.

## Conditional Visibility

The `visibility` setting controls when a field is displayed in forms based on the value of another field.

```json
{
	"visibility": {
		"watch": "fieldName",
		"value": "expectedValue",
		"operator": "=="
	}
}
```

**Properties:**

- **`watch`** (required) - The name of the field to watch for changes
- **`value`** (required) - The value(s) to compare against. Can be a single value or an array of values
- **`operator`** (optional) - The comparison operator to use (default: `==`)

### Supported Operators

**Equality Operators:**
- `==` - Equals (default)
- `!=` - Not equals

**Numeric Operators:**
- `>` - Greater than
- `<` - Less than
- `>=` - Greater than or equal
- `<=` - Less than or equal

**Array Operators:**
- `in` - Current value is in the expected value array
- `not_in` - Current value is not in the expected value array

**Special Operators (for array fields like checkbox, multiselect):**
- `empty` - Field array is empty
- `not_empty` - Field array has at least one value

### Examples

**Show field when another field has a specific value:**
```json
{
	"visibility": {
		"watch": "linkType",
		"value": "custom",
		"operator": "=="
	}
}
```

**Hide field when another field is checked:**
```json
{
	"visibility": {
		"watch": "useDefaultDescription",
		"value": "1",
		"operator": "!="
	}
}
```

**Show field when value is NOT in a list:**
```json
{
	"visibility": {
		"watch": "userRole",
		"value": ["guest", "basic"],
		"operator": "not_in"
	}
}
```

**Show field when multiselect contains a value:**
```json
{
	"visibility": {
		"watch": "contentTypes",
		"value": "gallery",
		"operator": "in"
	}
}
```

**Show field based on numeric comparison:**
```json
{
	"visibility": {
		"watch": "orderTotal",
		"value": "100",
		"operator": ">="
	}
}
```

**Show field when array field is not empty:**
```json
{
	"visibility": {
		"watch": "categories",
		"value": null,
		"operator": "not_empty"
	}
}
```

**Match multiple values (OR logic):**
```json
{
	"visibility": {
		"watch": "deliveryMethod",
		"value": ["standard", "express", "overnight"],
		"operator": "=="
	}
}
```
Field is visible if `deliveryMethod` matches ANY value in the array.

### Default Behavior

Fields with a `visibility` setting are **hidden by default** until the condition is met. This ensures fields appear only when they should, even on initial form load.
