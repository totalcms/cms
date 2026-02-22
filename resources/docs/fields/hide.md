# Hide Field

The `hide` setting allows you to completely hide a field from the admin form while still storing its data. This is useful for fields that should be managed programmatically or set via defaults rather than through the admin interface.

## Basic Usage

```json
{
	"hide": true
}
```

When `hide` is set to `true`, the field will have the `cms-hide` CSS class added, which hides it from view.

## Example Schema

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

## Common Use Cases

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

## Important Notes

- **Data Storage:** Hidden fields still store data in the object. They are just not visible in the admin form.
- **Default Values:** Hidden fields typically should have a `default` value or be populated programmatically.
- **CSS Class:** The field receives the `cms-hide` class. You can customize visibility with CSS if needed.
- **All Field Types:** The `hide` setting works with all field types.
