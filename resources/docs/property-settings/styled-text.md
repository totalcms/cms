---
title: "Styled Text"
description: "Configure the Tiptap rich text editor in Total CMS with toolbar options, inline styles, HTML snippets, color palettes, and image upload rules."
---

# Styled Text

The styled text field provides a rich text editor powered by Tiptap. It supports a wide range of settings for customizing the editor's appearance, toolbar, and behavior.

## Editor Height

Control the editor's height with these settings:

```json
{
  "height": 400
}
```

- **height** - Fixed height in pixels. The editor will have exactly this height with vertical scrolling. When omitted, the editor uses a flexible height between `heightMin` and `heightMax`.
- **heightMin** - Minimum editor height in pixels. Default: `200`
- **heightMax** - Maximum editor height in pixels. Default: `800`

## Character and Word Counters

The editor displays character and word counts in the footer. You can configure limits that highlight when exceeded.

```json
{
  "charCounterCount": true,
  "charCounterMax": 5000,
  "wordCounterCount": true,
  "wordCounterMax": 1000
}
```

- **charCounterCount** - Show character counter. Default: `false`
- **charCounterMax** - Maximum character limit. Displays as "X / 5000 characters" and highlights in red when exceeded.
- **wordCounterCount** - Show word counter. Default: `true`
- **wordCounterMax** - Maximum word limit. Displays as "X / 1000 words" and highlights in red when exceeded.

## Toolbar Configuration

Customize which buttons appear in the toolbar and their grouping:

```json
{
  "toolbarConfig": [
    { "name": "history", "buttons": ["undo", "redo"] },
    { "name": "text", "buttons": ["bold", "italic", "underline"] },
    { "name": "paragraph", "buttons": ["heading", "bulletList", "orderedList"] },
    { "name": "insert", "buttons": ["link", "image"] },
    { "name": "misc", "buttons": ["codeView", "fullscreen"], "align": "right" }
  ]
}
```

Each group has a `name`, a `buttons` array, and an optional `align` property (`"right"` to push the group to the right side of the toolbar).

**Available toolbar buttons:**

| Category | Buttons |
|----------|---------|
| History | `undo`, `redo` |
| Text Formatting | `bold`, `italic`, `underline`, `strike`, `superscript`, `subscript` |
| Colors & Styling | `textColor`, `textBgColor`, `inlineStyles`, `inlineClasses` |
| Block Formatting | `heading`, `bulletList`, `orderedList`, `blockquote`, `codeBlock`, `align` |
| Insert | `link`, `image`, `video`, `file`, `table`, `horizontalRule`, `hardBreak`, `htmlSnippets`, `anchor` |
| Editor Controls | `clearFormatting`, `codeView`, `fullscreen` |

**Default toolbar:**
```json
[
  { "name": "text", "buttons": ["bold", "italic", "underline"] },
  { "name": "paragraph", "buttons": ["heading", "bulletList", "orderedList", "blockquote"] },
  { "name": "insert", "buttons": ["link", "image", "horizontalRule"] },
  { "name": "misc", "buttons": ["clearFormatting", "codeView"], "align": "right" }
]
```

**Full toolbar with all buttons:**
```json
[
  { "name": "history", "buttons": ["undo", "redo"] },
  { "name": "text", "buttons": ["bold", "italic", "underline", "strike", "superscript", "subscript"] },
  { "name": "format", "buttons": ["textColor", "textBgColor", "inlineStyles", "inlineClasses"] },
  { "name": "paragraph", "buttons": ["heading", "bulletList", "orderedList", "blockquote", "codeBlock", "align"] },
  { "name": "insert", "buttons": ["link", "image", "video", "file", "table", "horizontalRule", "hardBreak", "htmlSnippets", "anchor"] },
  { "name": "misc", "buttons": ["clearFormatting", "codeView", "fullscreen"], "align": "right" }
]
```

## Custom Inline Styles

Define custom inline styles available from the toolbar's "Inline Styles" dropdown:

```json
{
  "inlineStyles": {
    "Large": "font-size: 1.25em",
    "Small": "font-size: 0.85em",
    "Uppercase": "text-transform: uppercase; letter-spacing: 0.05em",
    "Highlight": "background-color: yellow; padding: 0 0.25em"
  }
}
```

The key is the label shown in the dropdown, and the value is the CSS to apply. Defaults to Large, Small, and Uppercase if not specified.

## Custom Inline Classes

Define custom CSS classes available from the toolbar's "Inline Classes" dropdown:

```json
{
  "inlineClasses": {
    "Code": "cms-inline-code",
    "Highlighted": "cms-highlighted",
    "Badge": "cms-badge",
    "Important": "cms-important"
  }
}
```

The key is the label shown in the dropdown, and the value is the CSS class name to apply. Defaults to Code, Highlighted, and Badge if not specified.

## HTML Snippets

Define reusable HTML snippets that can be inserted from the toolbar. Use `{content}` as a placeholder for selected text:

```json
{
  "htmlSnippets": {
    "Button": "<button class=\"cms-button\">{content}</button>",
    "Callout": "<div class=\"cms-callout\">{content}</div>",
    "Alert": "<div class=\"alert alert-warning\">{content}</div>"
  }
}
```

## Custom Color Palette

Override the default color palette for text color and background color pickers. Each has its own `colors` array and `allowCustom` toggle:

```json
{
  "textColor": {
    "colors": ["#000000", "#ffffff", "#ff0000", "#00ff00", "#0000ff"],
    "allowCustom": true
  },
  "textBgColor": {
    "colors": ["#ffff00", "#00ffff", "#ff00ff", "#f0f0f0"],
    "allowCustom": false
  }
}
```

- **colors** - Array of hex colors for the picker. Uses a default 48-color palette if not specified.
- **allowCustom** - Show native color picker for custom colors not in the palette. Default: `true`

## Upload Settings

Configure uploads within the editor. Each upload type has its own rules setting for validation.

```json
{
  "imagePreset": "featured",
  "imageUploadRules": {
    "size": { "max": 5242880 },
    "filetype": ["image/jpeg", "image/png", "image/webp"]
  },
  "mediaUploadRules": {
    "size": { "max": 52428800 },
    "filetype": ["video/mp4", "audio/mpeg"]
  },
  "fileUploadRules": {
    "size": { "max": 10485760 }
  }
}
```

- **imagePreset** - Apply a named image preset to uploaded images
- **imageUploadRules** - Validation rules for image uploads (same format as [Image Validation](docs/property-settings/image-gallery) rules)
- **mediaUploadRules** - Validation rules for video and audio uploads
- **fileUploadRules** - Validation rules for file link uploads

## Complete Example

```json
{
  "heightMin": 300,
  "heightMax": 600,
  "wordCounterCount": true,
  "wordCounterMax": 2000,
  "charCounterCount": true,
  "toolbarConfig": [
    { "name": "history", "buttons": ["undo", "redo"] },
    { "name": "text", "buttons": ["bold", "italic", "underline", "strike"] },
    { "name": "paragraph", "buttons": ["heading", "bulletList", "orderedList", "blockquote"] },
    { "name": "insert", "buttons": ["link", "image", "table", "horizontalRule"] },
    { "name": "misc", "buttons": ["clearFormatting", "codeView"], "align": "right" }
  ],
  "inlineClasses": {
    "Lead Text": "lead",
    "Small Print": "small-print"
  },
  "htmlSnippets": {
    "Info Box": "<div class=\"info-box\">{content}</div>"
  },
  "imagePreset": "blog",
  "imageUploadRules": {
    "size": { "max": 2097152 },
    "filetype": ["image/jpeg", "image/png"]
  }
}
```
