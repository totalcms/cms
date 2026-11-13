---
title: "ImageWorks"
description: "ImageWorks reference for Total CMS image transformations: resizing, cropping, effects, watermarks, presets, defaults, and color palettes."
---

# ImageWorks

ImageWorks is the image processing engine in Total CMS. It transforms images on-the-fly by passing parameters to methods like `cms.media.imagePath()`, `cms.media.galleryPath()`, and `cms.render.image()`. Transformed images are cached automatically.

```twig
{# Basic usage #}
<img src="{{ cms.media.imagePath('hero', {w: 800, h: 600, fit: 'crop'}) }}">

{# Gallery image #}
<img src="{{ cms.media.galleryPath('vacation', 'sunset.jpg', {w: 400, fm: 'webp'}) }}">
```

## Resizing

| Parameter | Type | Description |
|-----------|------|-------------|
| `w` | integer | Width in pixels |
| `h` | integer | Height in pixels |
| `fit` | string | How the image fits the dimensions (see below) |
| `dpr` | float | Device pixel ratio (e.g., `2` for retina) |

### Fit Modes

| Value | Description |
|-------|-------------|
| `contain` | Resize to fit within dimensions, preserving aspect ratio |
| `max` | Same as contain but won't upscale |
| `fill` | Resize to fill dimensions, cropping as needed |
| `stretch` | Stretch to exact dimensions (distorts) |
| `crop` | Crop to exact dimensions |
| `crop-{position}` | Crop anchored to a position (see below) |
| `crop-focalpoint` | Crop using the image's saved focal point |

### Crop Positions

Use with `fit: 'crop-{position}'`:

`top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`

```twig
{# Crop from the top of the image #}
{{ cms.media.imagePath('hero', {w: 800, h: 400, fit: 'crop-top'}) }}

{# Crop using the focal point set in the admin #}
{{ cms.media.imagePath('portrait', {w: 300, h: 300, fit: 'crop-focalpoint'}) }}
```

## Format & Quality

| Parameter | Type | Description |
|-----------|------|-------------|
| `fm` | string | Output format: `jpg`, `png`, `gif`, `webp`, `avif` |
| `q` | integer | Quality (1-100, default varies by format) |

```twig
{# Convert to WebP at 80% quality #}
{{ cms.media.imagePath('photo', {w: 1200, fm: 'webp', q: 80}) }}

{# AVIF for maximum compression #}
{{ cms.media.imagePath('photo', {w: 1200, fm: 'avif', q: 70}) }}
```

## Effects & Filters

| Parameter | Type | Description |
|-----------|------|-------------|
| `blur` | integer | Blur amount (0-100) |
| `sharp` | integer | Sharpen amount (0-100) |
| `pixel` | integer | Pixelate amount (0-1000) |
| `filt` | string | Filter name, or a comma chain of up to 3 (`ember,vignette`). See [Photo Filters](#photo-filters) |
| `filtamt` | integer | Filter strength (0-100, default 100). Applies to the whole `filt` chain |
| `duo` | string | Two colors for the `duotone` filter, `shadow,highlight` (hex without `#`, or `palette0`-`palette4`) |
| `bri` | integer | Brightness adjustment (-100 to 100) |
| `con` | integer | Contrast adjustment (-100 to 100) |
| `gam` | float | Gamma correction (0.1 to 9.99) |

```twig
{# Blurred background #}
{{ cms.media.imagePath('bg', {w: 1920, blur: 20}) }}

{# Greyscale thumbnail #}
{{ cms.media.imagePath('portrait', {w: 200, filt: 'greyscale'}) }}

{# Sharpened product photo #}
{{ cms.media.imagePath('product', {w: 600, sharp: 15}) }}
```

### Photo Filters

`filt` applies a look to the image when it is rendered, so the original stays untouched and one edit to a template or preset changes the look site-wide. Filters are free in every edition.

![Every built-in filter on the same photo](docs/twig/images/imageworks-filters.jpg)

| Group | Filters |
|-------|---------|
| Looks | `sepia` (warm brown, classic), `bloom` (bright and airy), `harbor` (cool and crisp), `ember` (golden hour), `meadow` (soft and faded, green-yellow cast), `dusk` (moody, rose-violet), `attic` (vintage), `pop` (punchy), `matte` (lifted blacks, muted color), `polar` (cold and bright), `noir` (high-contrast black and white), `ash` (soft black and white) |
| Duotones | `lagoon` (navy to aqua), `sunset` (purple to orange), `fern` (green to sage), `blush` (burgundy to pink), `duotone` (your own two colors, see below) |
| Extras | `vignette` (darkens the corners), `grain` (film grain in the midtones, the same look at any image size), `warm` (a soft golden tint), `cool` (a soft blue tint), `leak` (warm light bleeding in from the top-right corner). Made for chains: they stack on top of a look |

`greyscale` still works exactly as before. `sepia` keeps its name, but since 3.6.10 it is a recipe like the other looks: the old version washed out to near-white on Imagick servers and crushed the shadows on GD, so existing `sepia` images re-render once with the new look.

```twig
{# One look #}
{{ cms.media.imagePath('hero', {w: 1600, filt: 'ember'}) }}

{# A chain is applied in order, left to right #}
{{ cms.media.imagePath('hero', {w: 1600, filt: 'harbor,vignette'}) }}

{# A look with film grain on top #}
{{ cms.media.imagePath('hero', {w: 1600, filt: 'ember,grain'}) }}

{# Half strength: the filtered image blended over the original #}
{{ cms.media.imagePath('portrait', {w: 600, filt: 'noir', filtamt: 50}) }}
```

Rules for the URL:

- Names are not case-sensitive: `Greyscale` is `greyscale`, and shares its cache file. A chain takes up to 3 names, and order matters.
- A name that is not registered (a typo, or a disabled extension's filter) is dropped silently. If none of the names is known, the image is served as it would be with no `filt`: unfiltered from the same cache file, or with the default `filt` when `imageworks.defaults` sets one. A malformed value, such as a name with a space or 4 names, is a 400.
- `filtamt` runs from 0 to 100, where 100 (the default) is full strength and 0 is no filter at all (the same cache file as no `filt`). The public URL rounds it to the nearest 5, so there are 21 strengths and not 101.
- `filt`, `filtamt` and `duo` are applied before watermarks and marks, so a logo keeps its colors under `noir`.
- Transparent PNG and WebP images stay transparent at any `filtamt`: only the colors are blended, never the alpha.
- An animated GIF is filtered frame by frame.

If `imageworks.defaults` sets `filtamt`, that value applies to URLs that do not send one, and a URL cannot override it back to 100.

### Duotone

`filt=duotone` maps the image's brightness onto two colors: `duo` is `shadow,highlight`. Each color is a 3 or 6 digit hex value without `#`, in any case (`fff`, `FFFFFF` and `ffffff` are the same color and the same cache file), or `palette0` to `palette4` to use one of the image's [own colors](#color-palette-integration). A pair with anything else in it, such as a color name, falls back to the default. `duo` only applies with the `duotone` filter and is ignored otherwise.

```twig
{# Fixed colors #}
{{ cms.media.imagePath('hero', {w: 1600, filt: 'duotone', duo: '1b1f3b,f4a261'}) }}

{# Black to the photo's dominant color, which is also the default #}
{{ cms.media.imagePath('hero', {w: 1600, filt: 'duotone', duo: '000000,palette0'}) }}

{# Duotone plus a vignette #}
{{ cms.media.imagePath('hero', {w: 1600, filt: 'duotone,vignette'}) }}
```

Without `duo`, `duotone` uses `000000,palette0`: a monochrome image in the photo's own color.

### Your own filters

Define filters in `tcms.php` as recipes: an ordered list of steps, as plain data. Once defined, a filter is used like a built-in, in `filt`, in presets and in the admin ImageWorks tool.

```php
return [
    'imageworks' => [
        'filters' => [
            'golden' => [
                'label' => 'Golden',
                'group' => 'look',
                'steps' => [
                    ['sepia', 0.25],
                    ['saturate', 1.3],
                    ['overlay', 'ff9a3c', 'soft-light', 0.25],
                    ['vignette', 0.3],
                ],
            ],
        ],
    ],
];
```

```twig
{{ cms.media.imagePath('hero', {w: 1600, filt: 'golden'}) }}
```

A filter needs a `steps` list (1 to 12 steps). `label` is the name shown in the admin tool, and `group` (`look`, `duotone` or `effect`, default `look`) decides where it sits in the admin strip: looks and then duotones share the first row, where one is picked at a time, and `effect` filters are listed under Extras, where they stack on top of the chosen look instead of replacing it. An extension's looks and duotones get a row of their own, titled with the extension's name; they are still picked one at a time along with the core looks, and its `effect` filters join Extras. Names are 1-48 lowercase letters, digits and hyphens. A recipe that is not valid is refused and logged, and the rest still load.

| Step | Arguments | What it does |
|------|-----------|--------------|
| `brightness` | factor (0-10) | Like CSS `brightness()`; 1 is unchanged |
| `contrast` | factor (0-10) | Like CSS `contrast()`; 1 is unchanged |
| `saturate` | factor (0-10) | Like CSS `saturate()`; 1 is unchanged |
| `grayscale` | amount (0-1) | Like CSS `grayscale()` |
| `sepia` | amount (0-1) | Like CSS `sepia()` |
| `hue-rotate` | degrees (-360 to 360) | Like CSS `hue-rotate()` |
| `fade` | amount (0-1) | Lifts the blacks for a matte look |
| `overlay` | color, mode, opacity (0-1) | A flat color layered over the image. Modes: `normal`, `multiply`, `screen`, `overlay`, `soft-light` |
| `vignette` | strength (0-1), optional color | Darkens toward the corners; the color defaults to black |
| `gradient-map` | 2 to 8 colors | Maps brightness onto the colors, shadows first. This is a duotone |
| `grain` | amount (0-1) | Monochrome film grain, strongest in the midtones; pure black and white are left alone. The grain grows with the image (one grain per pixel up to a 900px short side, then larger), so it looks the same at any size. The pattern is fixed, so the same image always renders the same |
| `leak` | color, position, amount (0-1) | A light leak: screens the color in from a corner (`top-left`, `top-right`, `bottom-left`, `bottom-right`) or an edge (`top`, `bottom`, `left`, `right`), fading out over 60% of the image |

Colors are 3 or 6 digit hex, with or without `#`.

The steps use the CSS filter scale, where 1 means unchanged. That is not the scale of the `bri` and `con` parameters (-100 to 100). The advantage is that you can try a look with CSS `filter:` in the browser and copy the numbers.

Notes:

- A filter with the same name as a built-in replaces it, `sepia` included. Only `greyscale` cannot be redefined.
- Editing a recipe re-renders its images automatically: the cache key carries a version of the recipe.
- Extensions can ship filters of their own. See [Image Filters](docs/extensions/extension-points#image-filters) in Extension Points.

#### Image size limit

On servers using the GD driver, filters run in PHP, pixel by pixel. A filter on an image larger than `imageworks.filterMaxPixels` (default 25,000,000 pixels, enough for a 5K retina image) is refused with a 400 and nothing is cached. On GD the limit covers the built-in filters and recipes from `tcms.php` or an extension; `greyscale` and an extension's own PHP class filters are not limited. Imagick runs the color steps natively, so there the limit applies only to filters with a `vignette`, `grain` or `leak` step (`ember`, `attic`, `noir`, `vignette`, `grain`, `leak`, and any recipe that uses one), the steps that are still computed in PHP. An animation counts every frame: a 10-frame GIF of 3,000,000 pixels is 30,000,000. A value of 0 or less turns the limit off. Raise or lower it in `tcms.php`:

```php
return ['imageworks' => ['filterMaxPixels' => 40000000]];
```

## Borders

| Parameter | Type | Description |
|-----------|------|-------------|
| `border` | string | Border in format: `size,color,method` |

Border methods: `overlay` (default), `shrink`, `expand`

```twig
{# 10px white border #}
{{ cms.media.imagePath('photo', {w: 500, border: '10,ffffff,overlay'}) }}
```

## Flipping & Orientation

| Parameter | Type | Description |
|-----------|------|-------------|
| `flip` | string | Flip: `h` (horizontal), `v` (vertical), `both` |
| `or` | string | Orientation: `auto`, `0`, `90`, `180`, `270` |

## Image Watermarks

Overlay another image as a watermark.

| Parameter | Type | Description |
|-----------|------|-------------|
| `mark` | string | Watermark image filename |
| `markw` | mixed | Watermark width (pixels or percentage like `25w`) |
| `markh` | mixed | Watermark height (pixels or percentage like `25h`) |
| `markpos` | string | Position: `top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`, or `tile` to repeat the logo over the whole image |
| `markpad` | integer | Padding from edge in pixels |
| `markalpha` | integer | Opacity (0-100, where 100 is fully opaque). Default: 100, or 30 when tiled |
| `markrepeat` | integer | Tiled mode only: logos across the image (1-12, default: 4) |
| `markfit` | string | How the watermark fits: `contain`, `max`, `fill`, `stretch`, `crop` |

```twig
{# Logo watermark in bottom-right corner #}
{{ cms.media.imagePath('photo', {
    w: 1200,
    mark: 'logo.png',
    markw: '15w',
    markpos: 'bottom-right',
    markpad: 20,
    markalpha: 70
}) }}
```

### Tiled Logo Watermarks

`markpos: 'tile'` repeats the logo over the whole image in offset rows, the
same way a [tiled text mark](#tiled-watermarks) does, so there is no clean
corner to crop out. The logo is faint (`markalpha` 30) unless you set the
opacity, and `markrepeat` sets how many logos run across the image.
`markw`, `markh`, `markpad` and `markfit` don't apply to a tiled logo. Use a
PNG with a transparent background: a JPEG logo tiles as solid rectangles.

```twig
{# Proof sheet: the studio logo repeated across the image #}
{{ cms.media.imagePath('photo', {
    w: 1600,
    mark: 'logo.png',
    markpos: 'tile',
    markrepeat: 5
}) }}
```

### Schema-Level Watermarks

You can configure watermarks at the schema, collection, or per-object level using property settings. These are applied automatically without needing to pass parameters in templates.

```json
{
    "image": {
        "field": "image",
        "label": "Photo",
        "settings": {
            "watermark": {
                "mark": "logo.png",
                "markw": "20w",
                "markpos": "bottom-right",
                "markpad": 15,
                "markalpha": 60
            }
        }
    }
}
```

Watermark settings follow the three-level override hierarchy: **schema → collection → per-object**. Override at the collection level in collection meta, or per-object using custom properties.

**Replacing the logo.** Watermarked renders are cached by the logo file's modified time, so uploading a new `logo.png` under the same name re-renders every image on its next request. The old renders stay on disk until you clear the cache. The same goes for an image placed with [`markimage`](#images-from-other-records).

### Protecting Original Images

Schema-level watermarks are not just a convenience — they are how you stop the
unmarked original being downloaded. Two behaviours make that work, and both are
deliberate:

**They apply even when the URL asks for nothing.** An image URL with no
parameters normally returns the original file untouched, which is how you fetch
the source image. When the property carries `settings.watermark`, that shortcut
is skipped: the image is processed and marked before it is served, so there is
no URL that yields a clean original.

**They override URL parameters.** The schema's watermark is drawn last, on top
of anything the URL asks for, and no URL parameter changes it, so a visitor
cannot weaken, move or remove the mark by editing the URL. Passing
`marktextsize=1` or `marktextalpha=0` against a schema that sets
`marktextsize=140` still produces the schema's watermark. A schema text mark
replaces the URL's own `marktext`, and a schema logo replaces the URL's `mark`;
layers the URL lists in [`marks`](#layered-marks) are drawn under the schema's.

If you need the unmarked file, read it from the object's property directly in a
template rather than through an ImageWorks URL.

### Limiting Watermarks by Size

Marking every derivative is rarely wanted — a 150px thumbnail with a watermark
across it is usually just ugly. The `limit` setting is a pixel threshold that
keeps small renders clean while still protecting anything large enough to be
worth taking:

```json
{
    "image": {
        "field": "image",
        "settings": {
            "watermark": {
                "marktext": "© Example Co",
                "marktextsize": 140,
                "marktextpos": "center",
                "limit": 800
            }
        }
    }
}
```

With `limit: 800`:

| Request | Watermarked? |
|---------|--------------|
| `w=300` (below the limit) | No |
| `w=1200` (above the limit) | Yes |
| `h=1000` (height above the limit) | Yes |
| No dimensions at all | **Yes** |

The last row is the important one. A request with no width or height is asking
for the original, which is exactly the case worth protecting, so it is treated
as over the limit rather than under it.

`dpr` counts. `w=600&dpr=3` renders an 1800px image, so with `limit: 800` it is
marked.

`limit` is a watermark-only setting and is removed before the remaining values
are passed to the image processor.

## Text Watermarks

Render text directly on images without needing a separate watermark image.

| Parameter | Type | Description |
|-----------|------|-------------|
| `marktext` | string | Text to render |
| `marktextfont` | string | Font family name (TTF/OTF from the watermark-fonts depot) |
| `marktextsize` | mixed | Font size in pixels, or relative to the image (`6w` is 6% of its width, `4h` 4% of its height). With `marktextw` the text wraps; see [Text size](#text-size) |
| `marktextcolor` | string | Text color as hex without `#` (e.g., `ffffff`) |
| `marktextbg` | string | Background color as hex (optional, transparent if not set) |
| `marktextpad` | integer | Padding around text in pixels (default: 10) |
| `marktextangle` | integer | Rotation angle in degrees (-360 to 360, default: 0) |
| `marktextpos` | string | Position (same options as image watermarks), or `tile` to repeat the text over the whole image |
| `marktextw` | mixed | Text width in pixels or relative to the image (e.g., `50w` for 50% of image width). Default: `40w` when `marktextsize` is not set |
| `marktextlines` | integer | Wrapped text only: the most lines before the font size steps down (1-6, default: 3) |
| `marktextalign` | string | How the lines line up: `left` (default), `center` or `right` |
| `marktextalpha` | integer | Opacity (0-100, where 100 is fully opaque). Default: 100, or 30 when tiled |
| `marktextstroke` | integer | Outline width as a percentage of the font size (0-25, default: 0) |
| `marktextstrokecolor` | string | Outline color as hex (default: `000000`) |
| `marktextshadow` | integer | Drop shadow offset as a percentage of the font size (0-25, default: 0) |
| `marktextshadowcolor` | string | Shadow color as hex (default: `000000`) |
| `marktextrepeat` | integer | Tiled mode only: marks across the image (1-12, default: 4) |

### Text Size

With no size set, the text spans 40% of the image's width, so the mark has
the same weight on a 400px thumbnail as on the 2,400px original.

- **`marktextw` alone** sets that width yourself: `25w` for a quarter of the
  image, or a pixel width. The text stays on one line and is scaled to fit,
  so a long title comes out small.
- **`marktextsize` alone** draws the text at that size, whatever the image
  size. If the text would be wider than the image, it is shrunk to 90% of the
  image width.
- **Both together wrap the text.** `marktextw` is the box and `marktextsize`
  the font size: the text breaks between words to stay inside the box (its
  plate and padding included). When it still needs more than `marktextlines`
  lines (default 3), or a single word is wider than the box, the font size
  steps down until it fits. A title that fits on one line is left as it is.
  Wrapping is a Pro edition feature: below Pro the text is drawn on one line
  at `marktextsize`, as it was before wrapping.

A relative size such as `6w` keeps a wrapped title the same shape at any
image size, which matters when the image is smaller than the size you asked
for (ImageWorks never enlarges a photo, so `w: 1200` on a 1,024px photo
gives a 1,024px card).

```twig
{# A share card title: up to three lines across 60% of the image #}
{{ cms.media.imagePath(post, {w: 1200, h: 630, fit: 'crop', marks: [
    {markfill: '0b0f1a', markgradient: 'bottom', markh: '100h', markpos: 'bottom', markalpha: 85},
    {marktext: post.title, marktextw: '60w', marktextsize: '6w', marktextlines: 3,
     marktextpos: 'bottom-left', marktextx: '5w', marktexty: '8h'}
]}, {collection: 'blog'}) }}

{# Centered over a darkened photo #}
{{ cms.media.imagePath(post, {w: 1200, h: 630, fit: 'crop', marks: [
    {markfill: '0b0f1a', markalpha: 65},
    {marktext: post.title, marktextw: '70w', marktextsize: '7w', marktextalign: 'center', marktextpos: 'center'}
]}, {collection: 'blog'}) }}
```

A line break in the text always starts a new line, though URLs do not accept
one: line breaks reach a mark only from settings stored on the server.

### Tiled Watermarks

`marktextpos: 'tile'` repeats the text over the whole image in offset rows,
so there is no clean corner to crop out. The text runs at 30° unless you set
`marktextangle`, and is faint (`marktextalpha` 30) unless you set the opacity.
`marktextrepeat` sets how many marks run across the image. `marktextx`,
`marktexty` and `marktextw` don't apply to a tiled mark.

```twig
{# Proof sheet: PROOF repeated across the image #}
{{ cms.media.imagePath('photo', {
    w: 1600,
    marktext: 'PROOF',
    marktextpos: 'tile',
    marktextrepeat: 5
}) }}
```

### Outline and Shadow

White text disappears on a pale sky and black text on a dark one. An outline
or a shadow keeps the mark readable on any image. Both are a percentage of the
font size, so they keep their look at any image size.

```twig
{# White text with a thin black outline #}
{{ cms.media.imagePath('photo', {
    w: 1200,
    marktext: '© Studio',
    marktextstroke: 3
}) }}

{# Soft drop shadow #}
{{ cms.media.imagePath('photo', {
    w: 1200,
    marktext: '© Studio',
    marktextshadow: 5,
    marktextshadowcolor: '333333'
}) }}
```

Text is drawn with the TTF or OTF font you choose, or Roboto by default.

### Custom Fonts

Text is drawn in Roboto Regular unless a mark names another font. To add one:

1. Open the **Depot** collection in the admin and create an object with the id `watermark-fonts`, or open it if it exists.
2. Upload your `.ttf` or `.otf` files to its depot field.
3. Name a font by its file name in `marktextfont`, with or without the extension: `Dorsa-Regular` finds `Dorsa-Regular.ttf`.

A name that matches no file draws in Roboto without an error, so check the spelling when the font does not change. The depot object's id is the `watermarkFontsDepot` ImageWorks setting, if you want another.

```twig
{# Simple copyright watermark #}
{{ cms.media.imagePath('hero', {
    w: 1200,
    marktext: 'Copyright 2026',
    marktextsize: 24,
    marktextcolor: 'ffffff',
    marktextpos: 'bottom-right',
    marktextpad: 15,
    marktextalpha: 70
}) }}

{# Custom font with background #}
{{ cms.media.imagePath('product', {
    w: 800,
    marktext: 'Premium Quality',
    marktextfont: 'Dorsa-Regular',
    marktextsize: 120,
    marktextcolor: 'ffffff',
    marktextbg: '000000',
    marktextpad: 20,
    marktextpos: 'bottom-right',
    marktextalpha: 80
}) }}

{# Diagonal DRAFT watermark #}
{{ cms.media.imagePath('document', {
    marktext: 'DRAFT',
    marktextsize: 200,
    marktextangle: -45,
    marktextcolor: 'ff0000',
    marktextpos: 'center',
    marktextalpha: 50
}) }}
```

## Layered Marks

`marks` takes a list of marks and draws them in order, each over the last.
Each entry takes the same parameters as a single mark: `mark*` for a logo, an
image or a [color fill](#color-fills), `marktext*` for text. That gives you a large headline over a small
caption, two logos, or a profile photo beside a name, all in one render.

> **Layered marks are a Pro edition feature.** That covers `marks`, images from other records (`markimage`, `markshape`), color fills (`markfill`, `markgradient`) and [wrapped text](#text-size). Below Pro, ImageWorks leaves those parameters out and draws the image without them, in a URL and in a schema's watermark settings alike, so a lapsed license gives plainer images, not broken ones. One logo and one line of text, tiled or not, work on every edition.

```twig
{# A share card: big title, caption, address, and the logo #}
{{ cms.media.imagePath(post, {w: 1200, h: 630, fit: 'crop', marks: [
    {marktext: post.title, marktextw: '70w', marktextpos: 'top-left', marktextx: '5w', marktexty: '8h', marktextbg: '0b0f1a'},
    {marktext: 'Read the full story', marktextw: '30w', marktextpos: 'bottom-left', marktextx: '5w', marktexty: '8h'},
    {marktext: 'example.com', marktextcolor: 'f97318', marktextw: '20w', marktextpos: 'top-right', marktextpad: '4w'},
    {mark: 'logo.png', markw: '8w', markpos: 'bottom-right', markpad: '4w'}
]}, {collection: 'blog'}) }}
```

- The flat parameters still work, and draw first: `mark` and `marktext`
  outside the list are a logo layer and a text layer, in that order. An entry
  with both `mark` and `marktext` is two layers too.
- One request draws at most **6 layers**, counting the flat ones. A public
  image URL asking for more is refused with a 400.
- Tiled marks (`markpos: 'tile'`, `marktextpos: 'tile'`) work as layers.
- A [schema watermark](#schema-level-watermarks) is drawn on top of every
  layer the URL asks for.

### Color Fills

`markfill` lays a color over the image, or over a band of it, so the text and
logos drawn after it stay readable on any photo. It is a layer like any other:
put it first in `marks` and everything after it sits on top.

| Parameter | Description |
|-----------|-------------|
| `markfill` | The color: hex (`0b0f1a`), or `palette0` to `palette4` for one of the image's own colors |
| `markalpha` | Opacity, 0-100 (default: 50) |
| `markgradient` | `top`, `bottom`, `left` or `right`: solid at that edge, fading to clear at the other. Leave it out for a flat color |
| `markw`, `markh` | Size of the fill (default: the whole image), e.g. `markh: '30h'` for a band 30% of the image tall |
| `markpos`, `markx`, `marky` | Where the fill sits, as for a logo (default: `center`) |

#### Fade from the bottom

The one to reach for first on a share card. The fade runs the full height of
the image, so the top of the photo stays clear and bright while the bottom,
where the text sits, is dark enough to read white text on any photo.

```twig
{{ cms.media.imagePath(post, {w: 1200, h: 630, fit: 'crop', marks: [
    {markfill: '0b0f1a', markgradient: 'bottom', markh: '100h', markpos: 'bottom', markalpha: 85},
    {marktext: post.title, marktextw: '60w', marktextsize: '6w', marktextpos: 'bottom-left', marktextx: '5w', marktexty: '8h'},
    {mark: 'logo.png', markw: '12w', markpos: 'top-right', markpad: '4w'}
]}, {collection: 'blog'}) }}
```

#### Two fades for bright photos

One fade strong enough for a white sky darkens the whole photo. Stack two
instead: a light fade over the full height, then a short, strong one at the
edge where the text sits. Fills are layers, so the second darkens the first.

```twig
{{ cms.media.imagePath(post, {w: 1200, h: 630, fit: 'crop', marks: [
    {markfill: '0b0f1a', markgradient: 'bottom', markh: '100h', markpos: 'bottom', markalpha: 50},
    {markfill: '0b0f1a', markgradient: 'bottom', markh: '45h', markpos: 'bottom', markalpha: 80},
    {marktext: post.title, marktextw: '60w', marktextsize: '6w', marktextpos: 'bottom-left', marktextx: '5w', marktexty: '8h'}
]}, {collection: 'blog'}) }}
```

#### Fade from the top

The same idea for a headline at the top: flip `markgradient`, `markpos` and
the text position.

```twig
{{ cms.media.imagePath(post, {w: 1200, h: 630, fit: 'crop', marks: [
    {markfill: '0b0f1a', markgradient: 'top', markh: '100h', markpos: 'top', markalpha: 85},
    {marktext: post.title, marktextw: '60w', marktextpos: 'top-left', marktextx: '5w', marktexty: '8h'}
]}, {collection: 'blog'}) }}
```

#### Fade from one side

Text down one side, with the photo's subject clear on the other. The fill is
`70w` wide, so the last 30% of the image is untouched.

```twig
{{ cms.media.imagePath(post, {w: 1200, h: 630, fit: 'crop', marks: [
    {markfill: '0b0f1a', markgradient: 'left', markw: '70w', markh: '100h', markpos: 'left', markalpha: 90},
    {marktext: post.title, marktextw: '35w', marktextpos: 'left', marktextx: '5w'}
]}, {collection: 'blog'}) }}
```

#### Darken the whole photo

A flat tint for text in the middle of the image. The photo shows through less
than with a fade, so keep it for busy photos where text anywhere is hard to read.

```twig
{{ cms.media.imagePath(post, {w: 1200, h: 630, fit: 'crop', marks: [
    {markfill: '0b0f1a', markalpha: 65},
    {marktext: post.title, marktextw: '70w', marktextpos: 'center'}
]}, {collection: 'blog'}) }}
```

#### A solid band

A strip of color behind a short label.

```twig
{{ cms.media.imagePath(post, {w: 1200, h: 630, fit: 'crop', marks: [
    {markfill: '0b0f1a', markh: '22h', markpos: 'bottom', markalpha: 90},
    {marktext: 'New on the blog', marktextw: '40w', marktextpos: 'bottom', marktexty: '6h'}
]}, {collection: 'blog'}) }}
```

#### Tips

- **How strong:** 60-75 for a flat tint behind white text, 85-100 for a fade.
  A fade loses opacity evenly from its solid edge, so halfway along it is at
  half the opacity you set; that is why it needs the higher number. Below
  about 50, a dark fill on a dark photo is hard to notice at all.
- **Near-black beats pure black.** A deep navy or charcoal such as `0b0f1a`
  looks richer than `000000` and still gives white text full contrast.
- **Palette colors follow the photo.** `palette0` is the image's main color,
  which can be light on one photo and dark on the next. White text on a light
  palette color is unreadable, so use a palette fill where the text color is
  chosen for it, or as a low-opacity tint under a hex fade.
- **Keep every mark in `marks`.** The flat `mark` and `marktext` parameters
  are drawn before the `marks` list, so they would end up under the fill.

### Images From Other Records

`markimage` places an image from another record, such as the author's photo on
a blog post's share image, or a member's photo on their profile card. It takes
the same sizing and position parameters as `mark`.

| Value | Image |
|-------|-------|
| `collection/id/property` | The image in an image field |
| `collection/id/property/name` | The gallery image with that file name (without its extension) |
| `collection/id/property/first` | The first gallery image (`last` for the last one) |

| Parameter | Description |
|-----------|-------------|
| `markimage` | The image to place, as above |
| `markshape` | `circle` cuts the image to a circle. With only `markw` set it is cut square first, around the image's focal point |

```twig
{# The author's photo, round, in the bottom-left corner #}
{{ cms.media.imagePath(post, {w: 1200, h: 630, fit: 'crop', marks: [
    {markimage: 'authors/' ~ post.author ~ '/photo', markshape: 'circle', markw: '15w', markpos: 'bottom-left', markpad: '4w'},
    {marktext: post.author_name, marktextw: '30w', marktextpos: 'bottom-left', marktextx: '22w', marktexty: '8h'}
]}, {collection: 'blog'}) }}
```

- Only image and gallery fields can be placed. `random` and `featured` are not
  offered, because the render is cached and would keep its first pick.
- When the record or its image is missing, that layer is left out and the rest
  of the image is drawn as usual.
- With `markfit: 'crop'`, any image is cut around its focal point.
- Every image ImageWorks can place is already public through its own
  ImageWorks URL; `markimage` reaches nothing that URL does not.

## Color Palette Integration

Images in Total CMS have an automatically extracted color palette (up to 5 colors). You can reference these palette colors in `border` and `bg` (background) parameters.

Use `palette0` through `palette4` to reference extracted colors:

```twig
{# Background color from the image's dominant color #}
{{ cms.media.imagePath('photo', {w: 800, bg: 'palette0'}) }}

{# Border using a palette color #}
{{ cms.media.imagePath('photo', {w: 500, border: '10,palette1,overlay'}) }}
```

## Defaults & Presets

### Defaults

Set default ImageWorks parameters that apply to **every** image transformation. Configure in `tcms.php`:

```php
return [
    'imageworks' => [
        'defaults' => [
            'q'  => 85,
            'fm' => 'webp',
        ],
    ],
];
```

Any parameters passed in templates override the defaults.

### Presets

Define reusable parameter sets that can be referenced by name. Configure in `tcms.php`:

```php
return [
    'imageworks' => [
        'presets' => [
            'thumbnail' => [
                'w'   => 200,
                'h'   => 200,
                'fit' => 'crop',
                'q'   => 80,
            ],
            'hero' => [
                'w'   => 1920,
                'h'   => 600,
                'fit' => 'crop-focalpoint',
                'fm'  => 'webp',
                'q'   => 85,
            ],
            'avatar' => [
                'w'   => 100,
                'h'   => 100,
                'fit' => 'crop',
            ],
        ],
    ],
];
```

Use presets by passing the `p` parameter:

```twig
{{ cms.media.imagePath('photo', {p: 'thumbnail'}) }}
{{ cms.media.imagePath('banner', {p: 'hero'}) }}

{# Override specific preset values #}
{{ cms.media.imagePath('photo', {p: 'thumbnail', w: 300}) }}
```

Presets that use `crop-focalpoint` in their `fit` value will automatically use each image's saved focal point.

## Warming the cache

The first visitor to a page pays for every image on it. Each size and format is made on that first request. AVIF can take a second or more per file. A gallery page right after an import, a deploy or a launch is slow for whoever sees it first. A crawler that hits it first may give up.

`tcms imageworks:warm` makes those files ahead of time, so every visit is fast. It writes only to the ImageWorks cache. It makes the same files the `/imageworks` route would serve, and it skips files that already exist, so it is safe to run again.

```bash
tcms imageworks:warm blog image --widths=480,768,1024 --formats=avif,webp,native
```

You must say which variants to make. A plain run never starts thousands of AVIF encodes by surprise. Give at least one of `--widths`, `--picture`, `--params`, `--preset` or `--url`. They combine and repeat.

### Choosing images

Without a property, the command warms every `image` and `gallery` property in the collection. Name one property to warm only that one.

A video's poster is warmed only when you name the video: `tcms imageworks:warm blog myvideo --params="w=1200"`. Templates usually ask for one poster size, so give the params your template uses rather than a whole grid.

An image inside a card or deck is named `parent/child`. Give both parts:

```bash
tcms imageworks:warm products mycard/photo --picture
tcms imageworks:warm products mydeck/photo --picture
```

`mycard/photo` is the photo in that card. `mydeck/photo` is the photo in every item of the deck. If the child is a video field, its poster is warmed. A plain run does not reach into cards and decks, so name each one you want.

An image inside a card or deck uses the watermark set on the card or deck, the same as on your pages.

### Choosing variants

Match what your templates ask for. A file the page never requests is wasted work.

- **A responsive image grid.** `--widths` and `--formats` make every width in every format. It makes only the widths you give. A width wider than the image makes a file at the image's own width, because that is what ImageWorks serves for it. When [size rounding](#request-limits) is on, a width rounds up the same way the site does. For example, with a step of 50, `--widths=333` makes the 350px file that a visitor gets. `native` means the image's own format (jpg, png), which is what the `<img>` fallback uses. `--widths` alone uses `native`. `--formats` needs `--widths` or `--picture`.
- **`cms.render.picture()` with its defaults.** Use `--picture`. It takes the widths and formats from the `imageworks.picture` setting (480, 768, 1024, 1440, 1920 and avif, webp) and adds `native`. Like `picture()`, it also adds the image's own width as the largest size. It only matches templates that call `picture()` without their own `widths` or `formats`. A GIF gets only `gif`, because `picture()` keeps GIFs as they are.
- **`picture()` with its own options.** Give the same options as a grid. `picture(img, {h: 600, fit: 'crop'})` is matched by `--picture --params="h=600&fit=crop"`. A `w` in `--params` caps the widths. `fm` is not allowed, because the grid sets the format.
- **`image()` calls.** Use `--params` for one exact variant, such as `--params="w=800&h=600&fit=crop"`. Repeat it for more variants. `--preset=medium` makes a preset. A preset `p` in `--params` with a grid makes one file per width, because the preset sets the format.
- **A URL from your page.** Copy an ImageWorks URL from your page's HTML and pass it to `--url`. The command takes the params and the format from it and ignores its collection and ID. A gallery URL that ends in `/first`, `/last` or `/featured` has no format, so only its params are used, as the route does.

### Two examples

With `--widths=480,768,1024,1440,1920 --formats=avif,webp,native`:

- A 1600px `photo.jpg` gets widths 480, 768, 1024, 1440 and 1600 in avif, webp and jpg. 1920 is wider than the photo, so it becomes 1600. That is 15 files.
- A 1000px `logo.png` gets widths 480, 768 and 1000 in avif, webp and png. 1024, 1440 and 1920 all become 1000. That is 9 files.

### Big galleries

Run with `--dry-run` first. It counts the files it would make and makes none, so you see "12,400 files" before you start an AVIF run.

```bash
tcms imageworks:warm photos --picture --dry-run
tcms imageworks:warm photos --picture
```

Narrow a run with a property name (`tcms imageworks:warm blog image ...`) or `--id=a,b,c`. Use `--memory` to raise the memory limit (default 512M).

### Deploys and cron

Because re-runs skip cached files, the command fits in a deploy script or a cron job. Run it as the web server user. A run as another user (such as root) can make cache folders the web server can't read, and those images then fail on the site. The command warns you when you are not the owner of the data folder.

For example:

```bash
tcms imageworks:warm blog --picture || echo "some images failed"
```

The command exits **1** when any image failed, and **0** otherwise. `--json` prints the counts and the list of failures.

### Limits

A card or deck image must be named as `parent/child`. Naming only the card or deck is an error, and the error lists the images inside it.

## Caching

Transformed images are cached automatically on disk. The cache directory is `.cache` within the image storage directory. When the source image changes, cached versions are regenerated on the next request.

To make the cache ahead of time, see [Warming the cache](#warming-the-cache). To clear the ImageWorks cache, use the emergency cache clear endpoint or clear the `.cache` directories from storage.

## Request limits

Every new size or setting in an ImageWorks URL makes ImageWorks render a new file. To stop a visitor (or a bot) from making thousands of them, the `/imageworks/` URLs have limits. Two are always on, and size rounding is a setting. These limits apply to image URLs, including the ones `cms.render` writes into your pages. An image that Total CMS renders on the server while it builds a page, such as `cms.media.imageDataUri()`, does not have them.

**Bad values get a 400 error.** Each parameter must have a value that ImageWorks can use. For example, `w` must be a whole number from 0 to 10000, `q` must be 0 to 100, and `fm` must be an image format. A request with a bad value gets a `400 Bad Request` and no image work is done. A parameter that ImageWorks does not use, such as `utm_source`, is ignored.

**Sizes can round up (off by default).** Without rounding, a bot can ask for every width from 1 to 2000 pixels and make the server render 2000 files for one image. To stop this, set **Round Image Sizes** on the ImageWorks settings page (or `sizeStep` in `tcms.php`) to a number of pixels, such as 50. A width or height then rounds up to the next multiple of that number. The widths in `imageworks.picture` and the sizes in your presets are not changed. For example, with 50, `w=333` serves a 350px image and `w=768` serves a 768px image. When you give both a width and a height, the height changes by the same amount, so the shape of the image stays the same. A size is never rounded past the size of the original image.

**Renders wait in line.** Only a few new images render at the same time: two for each CPU core. Other requests wait for up to 30 seconds, which is enough for the first view of a large gallery. A waiting request uses no CPU. If a request still can't start after the wait, it gets a `503 Service Unavailable` with a `Retry-After` header. Images that are already in the cache never wait.

You can change these limits in `tcms.php`:

```php
return [
    'imageworks' => [
        'sizeStep'    => 50,   // round sizes up to a multiple of this; 0 = exact sizes (default)
        'renderSlots' => 0,    // renders at the same time; 0 = two per CPU core
        'renderWait'  => 30,   // seconds a request waits for a free slot
    ],
];
```

A larger step means fewer files and less work for the server, but the images can be up to that many pixels bigger than you asked for. 50 is a good value for most sites. Leave it at 0 only if your pages use hand-written ImageWorks URLs that need an exact size.

## API Endpoint

ImageWorks images are served via the `/imageworks/` endpoint:

```
/imageworks/{type}/{id}/{filename}?{parameters}
```

Where `type` is `image` or `gallery`, `id` is the object ID, and parameters are ImageWorks transformation params. You typically don't need to construct these URLs manually — use `cms.media.imagePath()` and `cms.media.galleryPath()` instead.
