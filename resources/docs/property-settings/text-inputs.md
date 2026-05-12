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

## Text Transform

Automatically transform text on save. Useful for enforcing consistent casing (e.g., names, titles).

```json
{
  "textTransform" : "titlecase"
}
```

| Value | Input | Output |
|-------|-------|--------|
| `lowercase` | `JOHN DOE` | `john doe` |
| `uppercase` | `john doe` | `JOHN DOE` |
| `titlecase` | `john doe` | `John Doe` |
| `sentencecase` | `john doe` | `John doe` |
| `smart-titlecase` | `JOHN DOE` | `John Doe` |
| `smart-sentencecase` | `JOHN DOE` | `John doe` |

The transform is applied server-side on save, so it works regardless of how data is entered (admin, API, CLI, or import). It only applies to plain text — HTML content (styled text) is not affected.

The `smart-` variants only transform text that is entirely uppercase or entirely lowercase. If the text is already mixed case, it is left unchanged. This is useful for name fields where you want to fix `MARIA DE SILVA` → `Maria De Silva` but leave `Maria de Silva` alone if someone typed it correctly.

### Schema Example

```json
{
  "firstName": {
    "type": "string",
    "label": "First Name",
    "field": "text",
    "settings": {
      "textTransform": "titlecase"
    }
  }
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
