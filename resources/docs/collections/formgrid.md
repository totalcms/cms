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

Use `---Title Here---` to add a styled section heading:

```
id id
name name
---URL Setup---
url url
slug slug
---Dashboard Setup---
category sortBy
```

This creates:

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
