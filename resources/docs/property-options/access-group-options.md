# Access Group Options

Automatically populate form field options with all available access groups from the system. This is useful for assigning access control to objects, collections, or any entity that needs group-based permissions.

```json
{
	"accessGroupOptions" : true
}
```

The options will be formatted as `"id - description"` for easy identification. For example:
- `"admin - Administrators"`
- `"editors - Content Editors"`
- `"members - Site Members"`

## Common Use Cases

**Assigning access control to collections:**
```json
{
	"accessGroup": {
		"type"     : "string",
		"label"    : "Access Group",
		"field"    : "select",
		"settings" : {
			"accessGroupOptions" : true
		}
	}
}
```

**Multiple access groups (using list field):**
```json
{
	"accessGroups": {
		"type"     : "array",
		"label"    : "Access Groups",
		"field"    : "list",
		"settings" : {
			"accessGroupOptions" : true
		}
	}
}
```

**With sorting enabled:**
```json
{
	"accessGroup": {
		"type"     : "string",
		"label"    : "Access Group",
		"field"    : "select",
		"settings" : {
			"accessGroupOptions" : true,
			"sortOptions"        : true
		}
	}
}
```

## Field Types

Access group options work with any field that supports options or datalist:
- **select** - Dropdown selection (single choice)
- **list** - Multiple selection with search
- **radio** - Radio button groups
- Any text input field with datalist support
