---
title: "Color Field"
description: "Color picker field — renders the native swatch picker, accepts hex/rgb/hsl/oklch input, and stores both hex and OKLCH so templates can output any CSS color format."
updated: "2026-06-25"
related:
  - twig/colors
  - fields/static-options
  - twig/data
---

# Color Field

The color field is a color picker. In the admin it renders the browser's native swatch picker, and it stores the chosen color in **two** formats at once — a `hex` string and an **OKLCH** breakdown — so your templates can emit whatever CSS color format you need without converting by hand.

## How it works

- The admin control is a native `<input type="color">` swatch picker.
- The value is stored as an object with both representations:

  ```json
  {
  	"hex": "#1d9a6c",
  	"oklch": { "l": 62.8, "c": 0.108, "h": 159.2 }
  }
  ```

- On the way in, the field normalizes whatever you give it. A picker save sends hex, but a value arriving by another route — an API post, a CSV/JSON import, or a default — may be `#1d9a6c`, `rgb(29, 154, 108)`, `hsl(159, 68%, 36%)`, or `oklch(62.8% 0.108 159.2)`. Each is converted to hex and the OKLCH values are derived from it.
- An empty color defaults to black (`#000000`).

## Settings

All optional.

| Setting | Purpose | Default |
|---|---|---|
| `default` | Starting color for new objects (any accepted format) | empty → `#000000` |
| `options` | Preset swatches offered alongside the picker (see below) | none |

```json
{
	"label": "Brand Color",
	"help": "Pick your primary brand color.",
	"field": "color",
	"default": "#1d9a6c"
}
```

## Preset swatches

Give the field an `options` list to offer a set of preset colors as a datalist next to the picker — handy for keeping editors on a brand palette while still allowing a free choice:

```json
{
	"field": "color",
	"options": ["#1d9a6c", "#0b7285", "#5f3dc4", "#e8590c"]
}
```

Presets follow the same rules as every other option-backed field — see [Static Options](fields/static-options) for the full syntax (including pulling values from another property or collection).

## Using the value in a template

Read a color with `cms.data.color('id')` (or the `cms.color('id')` shorthand) and emit it in any CSS format — hex, `rgb()`, `hsl()`, `oklch()` — or derive lighter/muted/complementary variants with the OKLCH adjustment filters. That's all covered in **[Colors in Twig](twig/colors)**.
