---
title: "Colors in Twig"
description: "Read, output, and manipulate color-field values in Twig — emit any CSS color format (hex/rgb/hsl/oklch), set alpha, and lighten/darken/shift colors with OKLCH-based adjustment filters."
updated: "2026-06-25"
related:
  - fields/color
  - twig/data
  - twig/filters
---

# Colors in Twig

A [color field](fields/color) stores its value in two forms at once — a `hex` string and an **OKLCH** breakdown (`l`, `c`, `h`). Twig gives you a small toolkit on top of that to read a color, emit it in any CSS format, and derive new colors (lighter, more muted, complementary) without leaving the template.

## Reading a color

Use the [`cms.data`](twig/data) adapter. It returns the stored object, so you can reach `hex` and the OKLCH parts directly:

```twig
{% set brand = cms.data.color('brand') %}
{# cms.data.colour('brand') is a British-spelling alias #}

{{ brand.hex }}        {# #1d9a6c #}
{{ brand.oklch.l }}    {# lightness (0–100) #}
{{ brand.oklch.c }}    {# chroma (~0–0.4) #}
{{ brand.oklch.h }}    {# hue (0–360) #}
```

The default collection and property are both `color`; pass an options array to read elsewhere, e.g. `cms.data.color('hero', {collection: 'pages', property: 'accent'})`. `cms.color('id')` is a shorthand for the same read.

## Output formats

Pipe a color through a format filter to get a ready-to-use CSS value — convenient inside a `<style>` block or an inline `style` attribute. Outputs use modern, space-separated CSS color syntax.

| Filter | Output |
|---|---|
| `\| hex` | `#1d9a6c` |
| `\| rgb` | `rgb(29 154 108)` |
| `\| hsl` | `hsl(159 68% 36%)` |
| `\| oklch` | `oklch(62.800% 0.108 159.200)` |
| `\| color` | same as `\| oklch` (CSS-ready OKLCH) |

```twig
<div style="background: {{ cms.color('brand') | color }}">…</div>
```

### Alpha / opacity

`rgb`, `hsl`, `oklch`, and `color` take an optional alpha as a percentage (`0`–`100`):

```twig
{{ brand | rgb(60) }}    {# rgb(29 154 108 / 0.60) #}
{{ brand | oklch(25) }}  {# oklch(62.800% 0.108 159.200 / 0.25) #}
```

## Adjusting colors

Because the value carries OKLCH, you can derive related colors perceptually — a lighter hover state, a muted variant, the complement — and OKLCH keeps the result visually consistent in a way `lighten()`/`darken()` on RGB never managed.

Each adjuster takes a **relative delta** with an explicit sign and returns a **new color** (a `{hex, oklch}` array), so you pipe the result into an output filter:

| Filter | Adjusts | Example |
|---|---|---|
| `\| lightness(±n)` | lightness, `0–100` scale | `{{ brand \| lightness('+15') }}` |
| `\| chroma(±n)` | chroma (saturation), small decimals | `{{ brand \| chroma('-0.04') }}` |
| `\| hue(±n)` | hue in degrees (wraps at 360) | `{{ brand \| hue('+180') }}` |
| `\| adjustColor(l, c, h)` | all three at once (omit/`null` to skip one) | `{{ brand \| adjustColor('+10', '+0.05', '+30') }}` |

```twig
{# Lighten the brand color by 15 and emit it as hex #}
<a class="btn" style="--hover: {{ brand | lightness('+15') | hex }}">…</a>

{# Complementary accent: rotate the hue 180° #}
{% set accent = brand | hue('+180') %}
<span style="color: {{ accent | color }}">…</span>
```

Deltas are relative to the current value: `lightness('+15')` adds 15 to the lightness, `chroma('-0.04')` mutes it, `hue('+180')` rotates to the opposite side of the wheel. `adjustColor` applies up to all three in one step and leaves any argument you omit untouched.

## Turning a raw hex into a color

The adjustment and output filters expect a color object, not a bare hex string. To use them on a literal hex (or a hex you built elsewhere), pass it through `hexToColor` first:

```twig
{{ '#336699' | hexToColor | lightness('+10') | hex }}
```

## Putting it together

Generate a small set of CSS custom properties from a single brand color:

```twig
<style>
	:root {
		--brand:       {{ brand | color }};
		--brand-soft:  {{ brand | lightness('+18') | color }};
		--brand-dark:  {{ brand | lightness('-18') | color }};
		--brand-muted: {{ brand | chroma('-0.05') | color }};
		--brand-accent:{{ brand | hue('+180') | color }};
	}
</style>
```

For the field itself (admin control, storage shape, preset swatches) see the [Color field](fields/color); for the broader `cms.data` reference see [Twig Data](twig/data).
