---
title: "Property Options"
description: "Auto-populate select options and datalists from existing field values in a Total CMS collection for dynamic, data-driven form fields."
---

# Property Options

Get all values of a field and populate as select options or datalist. This dynamically generates the options list from existing data in the collection.

```json
{
	"propertyOptions" : true
}
```

When enabled, Total CMS scans all objects in the collection for unique values of the field and presents them as options. This is useful for fields like categories or tags where you want the options to reflect what's already been used.

## Sources

Instead of `true` (scan the current collection), set `propertyOptions` to a named string source to populate the field from elsewhere in the system:

| Source | Populates with |
|--------|----------------|
| `true` | Unique values of *this* field across the current collection's objects (the default). |
| `"collectionIds"` | The IDs of all collections. |
| `"collections"` | Unique category names across all collections. |
| `"schemas"` | Unique category names across all schemas. |
| `"schemaProperties"` | The property names defined on the current collection's object schema. |
| `"layouts"` | Site Builder layout templates (files in `tcms-data/builder/layouts/`). |
| `"pages"` | Site Builder page templates (files in `tcms-data/builder/pages/`). |
| `"pageMiddleware"` | Names of all registered page middleware (built-in and extension-provided). |
| `"locales"` | Available locale codes (BCP 47), as value/label pairs. |

```json
{
	"field"           : "select",
	"propertyOptions" : "pages"
}
```

### Inside extension settings

Extension settings schemas use the same field format, but field-level option settings must be nested under a `settings` object rather than placed at the top level:

```json
{
	"template" : {
		"field"    : "select",
		"label"    : "Custom Template",
		"settings" : {
			"propertyOptions" : "pages"
		}
	}
}
```

## Stored Value Merging

By default, any value already stored on the object is merged into the option list — even if it's no longer produced by the option source. This keeps previously-selected values visible so they aren't silently lost when the source changes.

To disable this and show *only* what the source emits, set `mergeStoredValues` to `false`:

```json
{
	"propertyOptions"   : "pageMiddleware",
	"mergeStoredValues" : false
}
```

This is useful when the option source is fully owned by code — for example, a `pageMiddleware` multicheckbox driven by extensions. With `mergeStoredValues: false`, disabling an extension also removes its middleware from the rendered options, even if a page still has the orphaned value stored.

The setting also applies to plain static option lists — set it to `false` whenever you want the rendered options to be exactly the configured set, with no rescue of stale stored values.
