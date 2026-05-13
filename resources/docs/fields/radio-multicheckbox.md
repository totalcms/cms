---
title: "Radio & Multicheckbox"
description: "Configure radio button and multicheckbox fields in Total CMS with responsive fieldGrid and fieldColumns layout settings for multi-column option displays."
---

# Radio & Multicheckbox

Radio and Multicheckbox fields allow users to select options from multiple choices. For how to define options, see [All Field Settings](docs/property-options/static-options).

Two layout settings are available for arranging options into multiple columns. Use one or the other — they control different flow directions.

## Grid Layout (`fieldGrid`)

Use the `fieldGrid` setting to specify the minimum width for each option in a responsive grid. Options flow **left-to-right, then wrap to new rows**. Supported by both `radio` and `multicheckbox` fields.

```json
{
    "fieldGrid": "250px"
}
```

- Each option has a minimum width of `250px`
- Options wrap to new rows when the container is full
- Row-major reading order (like text)

## Column Layout (`fieldColumns`)

Use the `fieldColumns` setting to arrange options in CSS columns. Options flow **top-to-bottom, then into the next column** — useful for longer option lists where alphabetical scanning by column is preferable. Supported by both `radio` and `multicheckbox` fields.

```json
{
    "fieldColumns": "150px"
}
```

- Each column has a minimum width of `150px`
- The browser creates as many columns as will fit in the container
- Column-major reading order (top-to-bottom within a column)
