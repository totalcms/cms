# Static Options

Options can be used on the following field types: **text** (as datalist), **select**, **multiselect**, **radio**, **multicheckbox**, **list**, and **color**.

## Simple List of Options

```json
["Option 1", "Option 2", "Option 3"]
```

## Options with Values

```json
[
	{"value" : "1", "label" : "Option 1"},
	{"value" : "2", "label" : "Option 2"},
	{"value" : "3", "label" : "Option 3"}
]
```

## Grouped Options

```json
{
	"Group 1" : ["Option 1", "Option 2"],
	"Group 2" : ["Option 3", "Option 4"]
}
```

## Grouped Options with Values

```json
{
	"Group 1" : [
		{"value" : "1", "label" : "Option 1"},
		{"value" : "2", "label" : "Option 2"}
	],
	"Group 2" : [
		{"value" : "3", "label" : "Option 3"},
		{"value" : "4", "label" : "Option 4"}
	]
}
```

## Field Type Behavior

| Field Type | Selection | Stored As | Use Case |
|-----------|-----------|-----------|----------|
| `radio` | Single | String | Mutually exclusive options (size, status, priority) |
| `select` | Single | String | Dropdown for single choice |
| `multicheckbox` | Multiple | Array | Non-exclusive options (features, amenities, tags) |
| `multiselect` | Multiple | Array | Dropdown for multiple choices |

**Important:** Ensure your schema `type` matches the field behavior:

**Radio/Select (single selection):**
```json
{
	"status": {
		"type": "string",
		"field": "radio",
		"options": ["Draft", "Published", "Archived"]
	}
}
```

**Multicheckbox/Multiselect (multiple selection):**
```json
{
	"tags": {
		"type": "array",
		"field": "multicheckbox",
		"options": ["News", "Featured", "Tutorial"]
	}
}
```

Using the wrong type (e.g., `type: "string"` with `field: "multicheckbox"`) may cause validation errors or unexpected data storage.
