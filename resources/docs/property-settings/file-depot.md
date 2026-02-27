---
title: "File & Depot"
description: "Configure file and depot upload fields in Total CMS with size limits, file type restrictions, filename validation, and collection-based access control."
---

# File & Depot

File fields handle single file uploads. Depot fields handle multiple file uploads. Both support validation rules and access control settings.

## Upload Validation

Use validation rules to restrict which files can be uploaded. You can pick and choose which rules you want to use.

```json
{
	"rules" : {
		"size"     : {"min":0,"max":5000},
		"count"    : {"max":20},
		"filetype" : ["application/pdf", "application/zip", "text/csv"],
		"filename" : ["report.pdf"]
	}
}
```

### Available Rules

- **size** - File size limits in kilobytes. `{"min":0,"max":5000}` allows files up to 5MB
- **count** - Maximum number of files (depot fields only). `{"max":20}` allows up to 20 files
- **filetype** - Allowed MIME types. Only files matching these types will be accepted
- **filename** - Allowed file names. Only files with these exact names will be accepted

### Common File Type Examples

**Documents only:**
```json
{
	"rules" : {
		"filetype" : ["application/pdf", "application/msword", "application/vnd.openxmlformats-officedocument.wordprocessingml.document"],
		"size"     : {"max":10000}
	}
}
```

**Spreadsheets:**
```json
{
	"rules" : {
		"filetype" : ["text/csv", "application/vnd.ms-excel", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"],
		"size"     : {"max":5000}
	}
}
```

**Archives:**
```json
{
	"rules" : {
		"filetype" : ["application/zip", "application/gzip", "application/x-tar"],
		"size"     : {"max":50000}
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
	"publicDocuments": {
		"$ref"     : "https://www.totalcms.co/schemas/properties/depot.json",
		"label"    : "Public Documents",
		"settings" : {
			"protectedByCollection" : false,
			"rules" : {
				"filetype" : ["application/pdf"],
				"size"     : {"max":10000}
			}
		}
	}
}
```

### Important Notes

- **Existing Files:** This setting only affects the default for new uploads. Existing files retain their current `protected` value.
- **Manual Override:** Users can still manually change the `protected` flag for individual files in the file management interface, regardless of this setting.
- **Security:** Setting to `false` makes files publicly accessible. Use with caution for sensitive content.
