---
title: "Report Form"
description: "Export collection data as CSV or JSON reports with field selection and filtering."
---

# Report Form

The report form lets users select a collection, choose which fields to include, apply filters, and download the results as CSV or JSON.

## Basic Usage

```twig
{# Report form with collection selector dropdown #}
{{ cms.form.report() }}

{# Report form locked to a specific collection #}
{{ cms.form.report('blog') }}
```

## Options

The second parameter accepts an options object:

| Option | Type | Description |
|--------|------|-------------|
| `include` | `string` | Pre-filled include filter value |
| `exclude` | `string` | Pre-filled exclude filter value |
| `includeOptions` | `array` | Datalist suggestions for the include filter input |
| `excludeOptions` | `array` | Datalist suggestions for the exclude filter input |

```twig
{{ cms.form.report('blog', {
    include: 'published:true',
    exclude: 'draft:true',
}) }}
```

## Filter Options (Datalist)

The `includeOptions` and `excludeOptions` arrays add a datalist dropdown to the filter inputs, providing precanned queries that users can select while still allowing custom input.

### Simple String Options

```twig
{{ cms.form.report('blog', {
    includeOptions: [
        'published:true',
        'featured:true',
        'category:news',
        'author:admin',
    ],
    excludeOptions: [
        'draft:true',
        'archived:true',
    ],
}) }}
```

### Label/Value Options

For more descriptive dropdown items, use `{value, label}` objects. The label is displayed in the dropdown while the value is inserted into the input.

```twig
{{ cms.form.report('blog', {
    includeOptions: [
        { value: 'published:true', label: 'Published posts' },
        { value: 'featured:true', label: 'Featured posts' },
        { value: 'category:news,published:true', label: 'Published news' },
        { value: 'author:admin,featured:true', label: 'Featured by admin' },
    ],
    excludeOptions: [
        { value: 'draft:true', label: 'Drafts' },
        { value: 'archived:true', label: 'Archived posts' },
    ],
}) }}
```

### Mixed Options

You can mix plain strings and label/value objects in the same array:

```twig
{{ cms.form.report('orders', {
    includeOptions: [
        'status:completed',
        { value: 'status:pending,priority:high', label: 'High priority pending' },
        { value: 'created:2024-01,status:completed', label: 'Completed in Jan 2024' },
    ],
}) }}
```

## Filter Syntax

The include and exclude filters use the format `field:value` with multiple filters separated by commas:

```
published:true                     # Single filter
category:news,featured:true        # Multiple filters (AND)
```

## How It Works

1. **Collection Selection** — If no collection is specified, a dropdown lists all available collections. When a collection is provided, it is locked in as a hidden field.
2. **Field Selection** — After choosing a collection, checkboxes for all available fields load via HTMX. Fields are sorted alphabetically with `id` always listed first. Deck properties are grouped separately with their sub-fields.
3. **Filtering** — Optional include/exclude filters narrow the exported data.
4. **Download** — Click **Download CSV** or **Download JSON** to export the report.

## CSS Classes

The report form uses the following classes for styling:

| Class | Element |
|-------|---------|
| `.report-form` | Outer container |
| `.report-collection-selector` | Collection dropdown wrapper |
| `.report-filters` | Filter fields wrapper |
| `.report-filter-field` | Individual filter field wrapper |
| `.include-filter-field` | Include filter wrapper |
| `.exclude-filter-field` | Exclude filter wrapper |
| `.report-filter-input` | Filter text input |
| `.report-fields` | Field checkbox container |
| `.report-field-grid` | Grid layout for field checkboxes |
| `.report-actions` | Download button container |
| `.report-download-btn` | Download button |
| `.report-download-csv` | CSV download button |
| `.report-download-json` | JSON download button |
