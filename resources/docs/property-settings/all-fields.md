# All Field Settings

These settings can be applied to **any field type**. They control universal behaviors like hiding fields and conditional visibility.

## Hide Field

The `hide` setting allows you to completely hide a field from the admin form while still storing its data. This is useful for fields that should be managed programmatically or set via defaults rather than through the admin interface.

```json
{
	"hide": true
}
```

When `hide` is set to `true`, the field will have the `cms-hide` CSS class added, which hides it from view.

### Example Schema

```json
{
	"internalStatus": {
		"type": "string",
		"field": "text",
		"label": "Internal Status",
		"default": "pending",
		"settings": {
			"hide": true
		}
	}
}
```

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
