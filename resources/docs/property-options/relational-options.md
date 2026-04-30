---
title: "Relational Options"
description: "Link form field options to another Total CMS collection or DataView with multi-field labels, sorting, and include/exclude filtering for relational data."
---

# Relational Options

The default value of the options is always the ID of the object. This is useful to
list all of the objects from another collection.

```json
{
  "relationalOptions" : {
  	"collection" : "feed",
  	"label"      : "title",
  	"value"      : "id"
  }
}
```

## Using a DataView

Instead of referencing a collection directly, you can populate options from a [DataView](/docs/dataviews). Use the `view` key instead of `collection`. The `view` and `collection` settings are mutually exclusive.

```json
{
  "relationalOptions" : {
  	"view"  : "my-dataview",
  	"label" : "title",
  	"value" : "id"
  }
}
```

All other settings (`label`, `format`, `value`, `include`, `exclude`, `sort`) work the same way with DataView-backed options.

## Custom Label Format

Use the `format` setting to build labels from multiple fields with arbitrary surrounding text. Wrap each field reference in `${ }` — anything outside the placeholders is rendered literally. This matches the same template syntax used by [`deckItemLabel`](docs/property-settings/deck) and [`autogen`](docs/property-settings/id).

```json
{
  "relationalOptions" : {
  	"collection" : "authors",
  	"format"     : "${firstName} ${lastName}",
  	"value"      : "id"
  }
}
```

This produces labels like `John Doe`. When `format` is set, it replaces the `label` setting — only the properties named inside `${ }` are fetched.

**With surrounding punctuation:**
```json
{
  "relationalOptions" : {
  	"collection" : "builder-pages",
  	"format"     : "${title} (${route})",
  	"value"      : "id"
  }
}
```
Produces labels like `About Us (/about)`.

**Three fields:**
```json
{
  "relationalOptions" : {
  	"collection" : "users",
  	"format"     : "${firstName} ${lastName} <${email}>",
  	"value"      : "id"
  }
}
```
Produces labels like `John Doe <john@example.com>`.

## Filtering Relational Options

You can filter which objects appear in relational dropdowns using `include` and `exclude` filters. This is useful for showing only published content, excluding drafts, or filtering by any object property.

> **See [Index Filter Documentation](docs/api/index-filter) for complete filtering syntax and examples.**

**Basic filtering:**
```json
{
  "relationalOptions" : {
  	"collection" : "blog",
  	"label"      : "title",
  	"value"      : "id",
  	"exclude"    : "draft:true"
  }
}
```
This will show all blog posts except drafts in the dropdown.

**Include only specific items:**
```json
{
  "relationalOptions" : {
  	"collection" : "products",
  	"label"      : "name",
  	"value"      : "id",
  	"include"    : "instock:true"
  }
}
```
This will show only in-stock products.

**Combined filters:**
```json
{
  "relationalOptions" : {
  	"collection" : "blog",
  	"label"      : "title",
  	"value"      : "id",
  	"include"    : "published:true",
  	"exclude"    : "draft:true,archived:true"
  }
}
```
This will show only published posts that are not drafts or archived.

**Shorthand syntax:**
```json
{
  "relationalOptions" : {
  	"collection" : "events",
  	"label"      : "name",
  	"value"      : "id",
  	"include"    : "featured",
  	"exclude"    : "cancelled"
  }
}
```
When no value is provided, it defaults to `true` (e.g., `featured` = `featured:true`).

**Array field filtering:**
```json
{
  "relationalOptions" : {
  	"collection" : "blog",
  	"label"      : "title",
  	"value"      : "id",
  	"include"    : "tags:featured",
  	"exclude"    : "tags:archived"
  }
}
```
Filters work with array fields like `tags` or `categories` by checking if the value exists in the array.

## Sorting Relational Options

You can sort the options by any property using the `sort` setting. Prefix with `-` for descending order.

```json
{
  "relationalOptions" : {
  	"collection" : "blog",
  	"label"      : "title",
  	"value"      : "id",
  	"sort"       : "title"
  }
}
```
This will sort the dropdown options alphabetically by title.

**Descending sort:**
```json
{
  "relationalOptions" : {
  	"collection" : "events",
  	"label"      : "name",
  	"value"      : "id",
  	"sort"       : "-date"
  }
}
```
This will sort events by date with the newest first.

**Combined with filters:**
```json
{
  "relationalOptions" : {
  	"collection" : "products",
  	"label"      : "name",
  	"value"      : "id",
  	"include"    : "instock:true",
  	"sort"       : "name"
  }
}
```
This will show only in-stock products, sorted alphabetically by name. Sorting works with both `collection` and `view` sources.

## Filter Logic

- **include** - Object must match ALL specified criteria (AND logic)
- **exclude** - Object is excluded if it matches ANY criteria (OR logic)
- **sort** - Sort results by a property (`property` for ascending, `-property` for descending)
- **Precedence** - Exclude takes precedence over include
- **Array fields** - Checks if value exists within array (case-insensitive for strings)
- **String values** - Case-insensitive matching for flexibility
- **Boolean values** - Strict comparison for optimal performance

Multiple filters are comma-separated: `"exclude": "draft:true,private:true"`
