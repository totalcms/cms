---
title: "ImageWorks"
description: "Complete reference for ImageWorks image transformation parameters including resizing, cropping, effects, watermarks, presets, defaults, and color palette integration."
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
| `filt` | string | Filter: `greyscale`, `sepia` |
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
| `markpos` | string | Position: `top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right` |
| `markpad` | integer | Padding from edge in pixels |
| `markalpha` | integer | Opacity (0-100, where 100 is fully opaque) |
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

## Text Watermarks

Render text directly on images without needing a separate watermark image.

| Parameter | Type | Description |
|-----------|------|-------------|
| `marktext` | string | Text to render |
| `marktextfont` | string | Font family name (TTF/OTF from the watermark-fonts depot) |
| `marktextsize` | integer | Text size in pixels (default: 500) |
| `marktextcolor` | string | Text color as hex without `#` (e.g., `ffffff`) |
| `marktextbg` | string | Background color as hex (optional, transparent if not set) |
| `marktextpad` | integer | Padding around text in pixels (default: 10) |
| `marktextangle` | integer | Rotation angle in degrees (-360 to 360, default: 0) |
| `marktextpos` | string | Position (same options as image watermarks) |
| `marktextw` | mixed | Max text width in pixels or relative (e.g., `50w` for 50% of image width) |
| `marktextalpha` | integer | Transparency (0-100, where 100 is fully opaque) |

### Custom Fonts

Upload TTF or OTF fonts to the watermark-fonts depot (configurable via `watermarkFontsDepot` setting). The default font is RobotoRegular.

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

## Caching

Transformed images are cached automatically on disk. The cache directory is `.cache` within the image storage directory. When the source image changes, cached versions are regenerated on the next request.

To clear the ImageWorks cache, use the emergency cache clear endpoint or clear the `.cache` directories from storage.

## API Endpoint

ImageWorks images are served via the `/imageworks/` endpoint:

```
/imageworks/{type}/{id}/{filename}?{parameters}
```

Where `type` is `image` or `gallery`, `id` is the object ID, and parameters are ImageWorks transformation params. You typically don't need to construct these URLs manually — use `cms.media.imagePath()` and `cms.media.galleryPath()` instead.
