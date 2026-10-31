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

### Protecting Original Images

Schema-level watermarks are not just a convenience — they are how you stop the
unmarked original being downloaded. Two behaviours make that work, and both are
deliberate:

**They apply even when the URL asks for nothing.** An image URL with no
parameters normally returns the original file untouched, which is how you fetch
the source image. When the property carries `settings.watermark`, that shortcut
is skipped: the image is processed and marked before it is served, so there is
no URL that yields a clean original.

**They override URL parameters.** Schema settings are merged *after* anything
in the query string, so a visitor cannot weaken, move or remove the mark by
editing the URL. Passing `marktextsize=1` against a schema that sets
`marktextsize=140` still produces the schema's watermark.

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

`limit` is a watermark-only setting and is removed before the remaining values
are passed to the image processor.

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

## Warming the cache

The first visitor to a page pays for every image on it. Each size and format is made on that first request. AVIF can take a second or more per file. A gallery page right after an import, a deploy or a launch is slow for whoever sees it first. A crawler that hits it first may give up.

`tcms imageworks:warm` makes those files ahead of time, so every visit is fast. It writes only to the ImageWorks cache. It makes the same files the `/imageworks` route would serve, and it skips files that already exist, so it is safe to run again.

```bash
tcms imageworks:warm blog image --widths=480,768,1024 --formats=avif,webp,native
```

You must say which variants to make. A plain run never starts thousands of AVIF encodes by surprise. Give at least one of `--widths`, `--picture`, `--params`, `--preset` or `--url`. They combine and repeat.

### Choosing variants

Match what your templates ask for. A file the page never requests is wasted work.

- **A responsive image grid.** `--widths` and `--formats` make every width in every format. It makes only the widths you give. A width wider than the image makes a file at the image's own width, because that is what ImageWorks serves for it. `native` means the image's own format (jpg, png), which is what the `<img>` fallback uses. `--widths` alone uses `native`. `--formats` needs `--widths` or `--picture`.
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

An image that has both an image watermark and a text watermark is made in two passes, and ImageWorks re-makes the second pass on every request. Warming cannot help those variants. The command counts them as "uncacheable" and tells you.

A property with a watermark needs an edition that includes watermarks. If the command can't confirm that edition from this machine, it stops and warms nothing. Run `tcms check` to see the license the command reads.

The command covers `image` and `gallery` properties. Images inside cards and decks, and video posters, are not warmed.

## Caching

Transformed images are cached automatically on disk. The cache directory is `.cache` within the image storage directory. When the source image changes, cached versions are regenerated on the next request.

To make the cache ahead of time, see [Warming the cache](#warming-the-cache). To clear the ImageWorks cache, use the emergency cache clear endpoint or clear the `.cache` directories from storage.

## API Endpoint

ImageWorks images are served via the `/imageworks/` endpoint:

```
/imageworks/{type}/{id}/{filename}?{parameters}
```

Where `type` is `image` or `gallery`, `id` is the object ID, and parameters are ImageWorks transformation params. You typically don't need to construct these URLs manually — use `cms.media.imagePath()` and `cms.media.galleryPath()` instead.
