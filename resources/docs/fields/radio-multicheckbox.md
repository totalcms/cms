# Radio and Multicheckbox Fields

Radio and Multicheckbox fields allow users to select a single option from multiple choices. They support grid layouts for better organization when you have many options.

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
