# Docs for Field Options


## Simple list of options

```json
["Option 1", "Option 2", "Option 3"]
```

## Options with values

```json
[
	{"value" : "1", "label" : "Option 1"},
	{"value" : "2", "label" : "Option 2"},
	{"value" : "3", "label" : "Option 3"}
]
```

## Grouped options

```json
{
	"Group 1" : ["Option 1", "Option 2"],
	"Group 2" : ["Option 3", "Option 4"]
}
```

## Grouped options with values

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

## Field Type Behavior Differences

### Radio Fields

Radio fields allow users to select **a single option** from the available choices. The selected value is stored as a single string.

**Example schema:**
```json
{
	"size": {
		"type": "string",
		"label": "Size",
		"field": "radio",
		"options": ["Small", "Medium", "Large"]
	}
}
```

**Stored value:**
```json
{
	"size": "Medium"
}
```

**Key characteristics:**
- Only one option can be selected at a time
- Selecting a different option automatically deselects the previous choice
- Stored as a simple string value
- Best for mutually exclusive choices (size, status, type, etc.)

### Multicheckbox Fields

Multicheckbox fields allow users to select **multiple options** from the available choices. The selected values are stored as an array of strings.

**Example schema:**
```json
{
	"features": {
		"type": "array",
		"label": "Features",
		"field": "multicheckbox",
		"options": ["WiFi", "Parking", "Pool", "Gym"]
	}
}
```

**Stored value:**
```json
{
	"features": ["WiFi", "Pool", "Gym"]
}
```

**Key characteristics:**
- Multiple options can be selected simultaneously
- Each checkbox can be toggled independently
- Stored as an array of values
- Best for non-exclusive choices (features, tags, categories, etc.)

### Quick Reference

| Field Type | Selection | Stored As | Use Case |
|-----------|-----------|-----------|----------|
| `radio` | Single | String | Mutually exclusive options (size, status, priority) |
| `select` | Single | String | Dropdown for single choice |
| `multicheckbox` | Multiple | Array | Non-exclusive options (features, amenities, tags) |
| `multiselect` | Multiple | Array | Dropdown for multiple choices |

### Schema Type Matching

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
