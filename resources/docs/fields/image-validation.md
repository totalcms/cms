# Image and File Validation

The following JSON is sample settings that you can use for image and file validation rules
for uploads. You do you need to supply all rules. You can pick and choose which rules you
want to use.

```json
{
	"rules" : {
		"size"        : {"min":0,"max":300},
		"height"      : {"min":500,"max":1000},
		"width"       : {"min":500,"max":1000},
		"size"        : {"min":0,"max":1000},
		"count"       : {"max":10},
		"orientation" : "landscape",
		"aspectratio" : "4:3",
		"filetype"    : ["image/jpeg", "image/png"],
		"filename"    : ["image.jpg"],
	}
}
```

## Protected by Collection

The `protectedByCollection` setting controls the default value of the `protected` property for file and depot fields. When a file or depot is protected, it inherits the access control settings from its parent collection.

**Default Behavior:** Without this setting, all files and depots default to `protected: true`, meaning they inherit collection-level access control.

```json
{
	"protectedByCollection" : false
}
```

### When to Use

**Public file downloads (protected: false):**
```json
{
	"downloads": {
		"$ref"     : "https://www.totalcms.co/schemas/properties/file.json",
		"label"    : "Public Downloads",
		"settings" : {
			"protectedByCollection" : false
		}
	}
}
```

Use `false` when:
- Files should be publicly accessible regardless of collection access control
- Public downloads section on website
- Open-access resources (documentation, marketing materials)
- Files that don't contain sensitive information

**Protected file downloads (protected: true, default):**
```json
{
	"privateFiles": {
		"$ref"     : "https://www.totalcms.co/schemas/properties/depot.json",
		"label"    : "Private Documents",
		"settings" : {
			"protectedByCollection" : true
		}
	}
}
```

Use `true` (or omit the setting) when:
- Files should respect collection access control
- Member-only content
- Premium downloads
- Sensitive documents
- Private media libraries

### How It Works

The `protectedByCollection` setting determines the **default** value for new uploads:

1. **New File Upload:** Uses `protectedByCollection` setting value (or `true` if not set)
2. **Existing File:** Retains its current `protected` value regardless of the setting
3. **Manual Override:** Users can manually change the `protected` value for individual files in the admin interface

### Depot Field Example

For depot (multiple file) fields, the setting works the same way:

```json
{
	"publicGallery": {
		"$ref"     : "https://www.totalcms.co/schemas/properties/depot.json",
		"label"    : "Public Gallery",
		"settings" : {
			"protectedByCollection" : false,
			"rules" : {
				"filetype" : ["image/jpeg", "image/png"]
			}
		}
	}
}
```

### Important Notes

- **Existing Files:** This setting only affects the default for new uploads. Existing files retain their current `protected` value.
- **Manual Override:** Users can still manually change the `protected` flag for individual files in the file management interface, regardless of this setting.
- **Security:** Setting to `false` makes files publicly accessible. Use with caution for sensitive content.
