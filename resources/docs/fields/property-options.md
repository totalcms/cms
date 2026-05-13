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
