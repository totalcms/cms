---
title: "Smart Date Defaults"
description: "Auto-populate date fields in Total CMS with onCreate and onUpdate settings for automatic timestamps when objects are created or modified."
---

# Smart Date Defaults

Use the following settings on a date field to auto-populate it when an
object is created or updated.

```json
{
  "onUpdate" : true
}
```

```json
{
  "onCreate" : true
}
```

- `onCreate` fills the date on the first save, when it is still empty, and never changes it after that.
- `onUpdate` stamps the date on every save, including the first one. For a "last modified" field, `onUpdate` on its own is enough.

If a field sets both, `onUpdate` wins: the date is re-stamped on every save.
