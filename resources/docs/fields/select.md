---
title: "Select & Multiselect"
description: "Configure select and multiselect dropdown fields in Total CMS with the clearValue button setting for optional selection clearing."
---

# Select & Multiselect

## Choosing several in a multiselect

A multiselect is a list of rows, each with a tick box. Click a row to pick it and click it again to take it off. The other rows stay as they were, so nobody has to hold a key down to pick more than one.

With the keyboard, the arrow keys move along the rows, Home and End go to the first and last, and Space or Enter picks the row you are on.

The list is drawn by the form and takes its colors from the form's theme. An option group shows as a heading.

## Clear Button

Select fields include a clear button (x) that appears when a value is selected, allowing users to quickly reset the selection. This feature is enabled by default but can be disabled using the `clearValue` setting.

```json
{
	"clearValue" : false
}
```

When enabled (default), a circular clear button appears on the right side of the select field whenever a value is selected. Clicking it clears the selection and returns to the placeholder state.
