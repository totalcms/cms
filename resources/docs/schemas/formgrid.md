---
title: "Form Grid Layout"
description: "Arrange admin form fields in multi-column layouts using CSS Grid syntax with column spanning, dividers, and section headers in Total CMS schemas."
---

# Form Grid Layout

The `formgrid` setting in a schema controls how form fields are arranged in the admin dashboard. It uses CSS Grid to create multi-column layouts, allowing you to organize fields in rows and columns.

## Basic Concept

Think of your form as a spreadsheet. Each row in your `formgrid` definition becomes a row in your form, and each word in that row represents a column. Fields are placed into named "cells" that you define.

```
row 1:  [field1] [field2]
row 2:  [field3] [field3]  <- field3 spans both columns
row 3:  [field4] [  .   ]  <- empty cell on the right
```

## Basic Syntax

In the schema form, the `formgrid` field is a textarea where:
- Each line represents a row in the grid
- Property names are separated by spaces

### Simple Two-Column Layout

```
title date
author category
content content
```

This creates:

```
+------------------+------------------+
|      title       |       date       |
+------------------+------------------+
|      author      |     category     |
+------------------+------------------+
|              content                |
+------------------+------------------+
```

## Spanning Multiple Columns

To make a field span multiple columns, repeat its name across those columns:

```
id id
title title
date author
content content
```

This creates:

```
+------------------+------------------+
|                 id                  |  <- spans both columns
+------------------+------------------+
|               title                 |  <- spans both columns
+------------------+------------------+
|       date       |      author      |
+------------------+------------------+
|              content                |  <- spans both columns
+------------------+------------------+
```

## Empty Cells

Use a period (`.`) to create an empty cell:

```
active .
id id
name category
```

This creates:

```
+------------------+------------------+
|      active      |     (empty)      |
+------------------+------------------+
|                 id                  |
+------------------+------------------+
|       name       |     category     |
+------------------+------------------+
```

## Section Dividers

Use `---` on its own line to add a horizontal divider:

```
name email
---
address city
state zip
```

This creates:

```
+------------------+------------------+
|       name       |      email       |
+------------------+------------------+
|  ─────────────────────────────────  |  <- visual divider
+------------------+------------------+
|      address     |       city       |
+------------------+------------------+
|       state      |       zip        |
+------------------+------------------+
```

## Section Headers

Use `--- My Header` or the legacy `---Title Here---` syntax to add a styled section heading:

```
id id
name name
--- URL Setup
url url
slug slug
--- Dashboard Setup
category sortBy
```

Both `--- My Header` (new) and `---Title Here---` (still supported) produce the same result:

```
+------------------+------------------+
|                 id                  |
+------------------+------------------+
|                name                 |
+------------------+------------------+
|            URL Setup                |  <- styled heading
+------------------+------------------+
|                url                  |
+------------------+------------------+
|                slug                 |
+------------------+------------------+
|         Dashboard Setup             |  <- styled heading
+------------------+------------------+
|     category     |      sortBy      |
+------------------+------------------+
```

## Fieldsets

Use `[[ ]]` to group related fields inside a styled fieldset container. The text after `[[` on the same line is an optional legend:

```
id id
name name
---
[[ My Legend
field1 field2
field3 field4
]]
```

This creates:

```
+------------------+------------------+
|                 id                  |
+------------------+------------------+
|                name                 |
+------------------+------------------+
|  ─────────────────────────────────  |  <- divider
+--────────────────────────────────── +
| My Legend                           |  <- fieldset with legend
| +------------------+---------------+|
| | field1          | field2         ||
| +------------------+---------------+|
| | field3          | field4         ||
| +------------------+---------------+|
+--────────────────────────────────── +
```

Fields named inside a `[[ ]]` block render inside that styled fieldset and leave the outer grid. The interior is laid out as its own mini-grid using the same row syntax. Dividers (`---`) and headers work inside a fieldset too:

```
id id
---
[[ Contact Details
first_name last_name
---
email phone
]]
address address
```

**Important notes:**
- A fieldset may contain another fieldset, and may contain an accordion group. But the outer `[[` closes at the **first** `]]`, so anything written after an inner block belongs to the outer grid, not to the outer fieldset — put the nested block last
- The reserved area prefixes `formgrid-fieldset-N` and `formgrid-accordion-N` should not be used as field names
- If the legend is omitted (e.g. `[[` with nothing after it), the fieldset renders without a legend

## Accordions

Use `>>` to open a collapsible panel and `<<` to close the group. The text after
`>>` is the panel title:

```
id id
>> Advanced Options
slug template
<<
```

This creates:

```
+------------------+------------------+
|                 id                  |
+------------------+------------------+
| > Advanced Options                  |  <- collapsed panel
+-------------------------------------+
```

### Linked Panels

Consecutive `>>` panels before a `<<` form one accordion: the first panel opens,
and opening any panel closes its siblings.

```
>> Content
body body
>> SEO
seoTitle seoDescription
>> Advanced
slug template
<<
```

This creates:

```
+-------------------------------------+
| v Content                           |  <- open
| +---------------------------------+ |
| |              body               | |
| +---------------------------------+ |
+-------------------------------------+
| > SEO                               |
+-------------------------------------+
| > Advanced                          |
+-------------------------------------+
```

### One Panel or Many

The `<<` is what defines the group, and the size of the group decides the
starting state:

- **One panel** in a group renders **closed**. Use this to tuck advanced or
  rarely-touched fields out of the way.
- **Two or more panels** render with the **first one open**, and only one panel
  is open at a time.

So two `<<`-terminated runs are two independent accordions, both closed, neither
one's state affecting the other:

```
>> Section 1
title title
<<
>> Section 2
id id
<<
```

### Panel Contents

A panel's interior is laid out as its own mini-grid using the same row syntax.
Dividers (`---`), headers (`--- My Header`) and fieldsets (`[[ ]]`) all work
inside a panel:

```
>> Contact Details
first_name last_name
--- Mailing
[[ Address
street street
city zip
]]
<<
```

**Important notes:**

- Panels cannot nest. `>>` always ends the panel it appears in and starts the
  next one, so there is no way to write a panel inside a panel.
- A `<<` with no panel open is ignored.
- Leaving off the closing `<<` is allowed - the group runs to the end of the
  formgrid.
- A `>>` with no title after it is titled `Section 1`, `Section 2`, and so on
  within its group.
- A required field inside a closed panel is safe. A failed save opens the panel
  holding the first problem, and any panel containing an invalid field shows a
  red header while it stays shut.

### Fields Inside a Collapsed Panel

Every field on a form is built when the page loads, including the fields in a
panel that starts closed - and a collapsed panel does not render its contents,
so a field built there cannot measure or read anything on screen. Most fields do
not care. A few build a widget that does: a `list` field, for one, would end up
with chips that show no text.

Those fields rebuild themselves the first time their panel is opened, so you do
not need to do anything. It happens once per panel; after that you may have been
typing in it, and rebuilding would throw your edits away.

If you are writing a custom field type whose JavaScript reads or measures the
DOM while it is being constructed, override `reinit()` on your field class to
rebuild that part. It is a no-op by default, and it is called only when the
field's container actually becomes visible.

## Three or More Columns

The grid automatically sizes based on the row with the most columns:

```
id id id
first middle last
address address address
city state zip
content content content
```

This creates a three-column layout:

```
+------------+------------+------------+
|                  id                  |
+------------+------------+------------+
|   first    |   middle   |    last    |
+------------+------------+------------+
|               address                |
+------------+------------+------------+
|    city    |   state    |    zip     |
+------------+------------+------------+
|               content                |
+------------+------------+------------+
```

## Important Rules

### Every Property Must Be Included

All properties defined in your schema must appear somewhere in the formgrid. Missing properties will cause the form layout to break.

If your schema has `title`, `date`, and `content` properties, this formgrid is **incorrect**:

```
title date
```

The `content` property is missing and must be added.

### Syntax Errors Break the Layout

The formgrid parser is strict. Common issues include:
- Missing properties
- Typos in property names
- Inconsistent column counts without proper spanning
- Invalid characters in property names

### Valid Property Name Characters

Property names in the grid must follow CSS identifier rules:
- Start with a letter, underscore, or hyphen
- Followed by letters, digits, hyphens, or underscores
- The special `.` character is reserved for empty cells

## Real-World Examples

### Blog Post Schema

```
id id
title title
draft featured
date author
image image
categories categories
tags tags
summary summary
content content
media media
gallery gallery
extra extra
updated created
```

### User Authentication Schema

```
active active
id id
image image
name name
email email
password password
groups loginCount
expiration created
maxLoginCount lastlogin
```

### Email Template with Dividers

```
active .
id id
name category
description description
---
to from
toName fromName
replyTo .
cc bcc
---
subject subject
bodyHtml bodyHtml
bodyText bodyText
```

### Collection Settings with Named Sections

```
id id
name name
schema schema
---URL Setup---
url url
prettyUrl prettyUrl
---Dashboard Setup---
category labelPlural
sortBy labelSingular
---Public Access---
publicOperations publicOperations
groups groups
```

## Tips

1. **Start simple**: Begin with a single-column layout, then add complexity
2. **Use sections**: Break long forms into logical sections with headers or dividers
3. **Test your layout**: After making changes, preview the form in the admin dashboard
4. **Match column counts**: Ensure each row uses the same total number of cells (using spanning or `.` for empty cells)
5. **Keep related fields together**: Group related fields on the same row or in the same section
