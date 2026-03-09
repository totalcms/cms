---
title: "Radio & Multicheckbox"
description: "Configure radio button and multicheckbox fields in Total CMS with responsive fieldGrid layout settings for multi-column option displays."
---

# Radio & Multicheckbox

Radio and Multicheckbox fields allow users to select options from multiple choices. For how to define options, see [All Field Settings](docs/property-options/static-options).

## Grid Layout Settings

Use the `fieldGrid` setting to specify the minimum width for each option in the grid. This setting is supported by both `radio` and `multicheckbox` fields. By default, options display in a single column (full width). When you specify a `fieldGrid` value, the options will automatically flow into a responsive grid layout.

```json
{
    "fieldGrid": "250px"
}
```

This creates a responsive grid where:
- Each option has a minimum width of `250px`
- Options automatically wrap to new rows when needed
- Grid adjusts based on container width
