---
title: "Code"
description: "Configure the CodeMirror-powered code editor field in Total CMS with syntax highlighting modes, HTML sanitization, and embed code support."
---

# Code

The code field provides a syntax-highlighted code editor powered by CodeMirror. It supports multiple programming languages and can be customized with various settings.

```json
{
  "mode"          : "twig",
  "theme"         : "elegant",
  "rows"          : 10,
  "minHeight"     : 200,
  "maxHeight"     : 600,
  "lineNumbers"   : true,
  "lineWrapping"  : true,
  "indentUnit"    : 2,
  "tabSize"       : 2,
  "foldGutter"    : true,
  "matchBrackets" : true,
  "autoCloseTags" : true,
  "fullscreen"    : true
}
```

## Available Settings

- **mode** - The syntax highlighting mode. Supported values:
  - `"twig"` - Twig templating
  - `"html"` or `"htmlmixed"` - HTML with embedded CSS/JS
  - `"css"` - CSS stylesheets
  - `"javascript"` or `"js"` - JavaScript
  - `"php"` - PHP code
  - `"markdown"` - Markdown text
  - Any other CodeMirror mode name

- **theme** - The color theme. Default is `"elegant"` (light theme)

- **lineNumbers** - Show line numbers in the gutter. Default: `true`

- **lineWrapping** - Wrap long lines. Default: `true`

- **indentUnit** - Number of spaces per indentation level. Default: `2`

- **tabSize** - Width of a tab character. Default: `2`

- **foldGutter** - Enable code folding in the gutter. Default: `true`

- **matchBrackets** - Highlight matching brackets. Default: `true`

- **autoCloseTags** - Auto-close HTML/XML tags. Default: `true`

- **fullscreen** - Show a fullscreen toggle button in the bottom-right corner of the editor. Press `Esc` to exit fullscreen. Default: `true`

### Editor Size

The code editor automatically grows to fit its content. You can control the sizing with these settings:

- **rows** - Number of text rows for the initial/minimum height calculation. Default: `10`

- **minHeight** - Minimum height in pixels. If set, overrides the height calculated from `rows`. The editor will never be shorter than this value.

- **maxHeight** - Maximum height in pixels for auto-grow. The editor will stop growing automatically beyond this height and scroll instead. Users can still drag the resize handle past this limit for temporary extra space. Default: no limit

The editor also has a drag handle on the bottom edge that allows users to manually resize the editor to any height they need.

## Example Usage

```json
{
  "snippet": {
    "type"     : "string",
    "label"    : "Code Snippet",
    "field"    : "code",
    "settings" : {
      "mode"         : "javascript",
      "theme"        : "elegant",
      "maxHeight"    : 500,
      "lineNumbers"  : true,
      "indentUnit"   : 4
    }
  }
}
```

## Code Fields for Embed Codes and Third-Party Widgets

**IMPORTANT:** When using code fields for third-party embed codes (like TidyCal, Google Analytics, social media widgets, etc.), you **must disable HTML sanitization** to preserve scripts and data attributes.

By default, Total CMS sanitizes all HTML content for security, which removes:
- `<script>` tags
- `data-*` attributes
- Event handlers
- Dangerous protocols

This protects against XSS attacks but breaks third-party embed codes that rely on these features.

**Recommended Usage:**

Use the `code.json` property reference and set `htmlclean: false`:

```json
{
  "embedCode": {
    "$ref"     : "https://www.totalcms.co/schemas/properties/code.json",
    "label"    : "Embed Code",
    "field"    : "code",
    "settings" : {
      "htmlclean" : false,
      "mode"      : "html"
    }
  }
}
```

**Alternative (without property reference):**

```json
{
  "embedCode": {
    "type"     : "string",
    "label"    : "Third-Party Embed Code",
    "field"    : "code",
    "settings" : {
      "htmlclean" : false,
      "mode"      : "html"
    }
  }
}
```

**Examples of embed codes that require `htmlclean: false`:**

```html
<!-- TidyCal scheduling widget -->
<div class="tidycal-embed" data-path="username/consultation"></div>
<script src="https://asset-tidycal.b-cdn.net/js/embed.js" async></script>

<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=GA_MEASUREMENT_ID"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
</script>

<!-- Social media widgets, video embeds, etc. -->
```

**Security Note:** Only use `htmlclean: false` for code fields where you control the content or trust the source. Never allow untrusted users to submit content to fields with HTML sanitization disabled.
