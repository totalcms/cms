---
title: "Text & Textarea"
description: "Configure text and textarea fields in Total CMS with maxlength, minlength, pattern validation, readonly, disabled, rows, and HTML sanitization settings."
---

# Text & Textarea

## Text Input Settings (text, url, tel, phone, etc.)

The following can be used on text fields to limit the number of characters.

```json
{
  "maxlength" : 100,
  "minlength" : 10,
  "pattern"   : "/ab+c/",
  "readonly"  : true,
  "disabled"  : true,
  "class"     : "custom-class"
}
```

## Textarea Settings

```json
{
  "rows" : 10
}
```

## Purifying HTML in Text

Default all text will be scanned for HTML and sanitized to help prevent from XSS attacks.
You can disable this by setting the following.

```json
{
  "htmlclean" : false
}
```
