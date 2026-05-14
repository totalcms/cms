---
title: "Radio & Multicheckbox"
description: "Configure radio button and multicheckbox fields in Total CMS with responsive fieldGrid and fieldColumns layout settings for multi-column option displays."
---

# Radio & Multicheckbox

Radio and Multicheckbox fields allow users to select options from multiple choices. For how to define options, see [All Field Settings](docs/fields/static-options).

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

## Required Behavior

Radio and multicheckbox fields handle the `required` setting differently, because the semantics of "required" don't translate cleanly to a group of inputs.

### Radio

A required radio field uses native HTML5 validation — the browser ensures **one option is selected** before the form can submit. The `required` attribute is emitted on every individual radio input in the group; this is the standard HTML pattern for radio groups.

```json
{
    "deliveryMethod": {
        "type": "string",
        "field": "radio",
        "label": "Delivery Method",
        "settings": {
            "required": true
        },
        "options": [
            {"value": "standard", "label": "Standard"},
            {"value": "express", "label": "Express"}
        ]
    }
}
```

If the user submits without choosing an option, the browser displays its native "Please select one of these options" prompt.

### Multicheckbox

A required multicheckbox field means **at least one option must be checked**. This cannot use the native HTML `required` attribute (which would require *every* checkbox to be checked), so the field uses a custom JS validator instead. The field's container receives `data-required="true"` and the validator runs on form submit.

```json
{
    "interests": {
        "type": "array",
        "field": "multicheckbox",
        "label": "Interests",
        "settings": {
            "required": true
        },
        "options": [
            {"value": "news", "label": "News"},
            {"value": "events", "label": "Events"},
            {"value": "products", "label": "Products"}
        ]
    }
}
```

If no options are checked, the user sees: *"Please select at least one option."*

### Schema-Level Required

Both field types also respect the schema's top-level `required` array. For multicheckbox specifically, an empty array (`[]`) is rejected by the schema validator at save time, mirroring the client-side "at least one" check. See [Schema Validation](../schemas/validation.md#required-fields) for details.

For conditional required scenarios — where the field is only required based on another field's value — use `settings.required` paired with `settings.visibility` and **leave the field out of the schema's `required` array**. See [Required (Form-Level)](all-fields.md#required-form-level).
