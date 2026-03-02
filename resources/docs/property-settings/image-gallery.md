---
title: "Image & Gallery"
description: "Configure image and gallery upload fields in Total CMS with validation rules, location stripping, and image or text watermark settings."
---

# Image & Gallery

## Upload Validation

The following JSON is sample settings that you can use for image and file validation rules
for uploads. You do you need to supply all rules. You can pick and choose which rules you
want to use.

```json
{
	"rules" : {
		"size"        : {"min":0,"max":300},
		"height"      : {"min":500,"max":1000},
		"width"       : {"min":500,"max":1000},
		"size"        : {"min":0,"max":1000},
		"count"       : {"max":10},
		"orientation" : "landscape",
		"aspectratio" : "4:3",
		"filetype"    : ["image/jpeg", "image/png"],
		"filename"    : ["image.jpg"],
	}
}
```

## Skip EXIF and Palette Extraction

By default, every image upload extracts EXIF metadata and generates a color palette. For large gallery uploads (50+ images), this can significantly slow down the upload process. This works on both image and gallery fields. You can disable either or both per-field using these settings:

- **extractExif** — When `false`, skips EXIF/IPTC/XMP metadata extraction. Default: `true`.
- **extractPalette** — When `false`, skips color palette generation. Default: `true`.

```json
{
    "extractExif": false,
    "extractPalette": false
}
```

### Important Notes

- **Defaults to enabled** — Omitting these settings preserves current behavior (both extraction types run).
- **Applies to new uploads only** — Existing images retain their saved metadata.
- **EXIF alt/tags** — When `extractExif` is `false`, EXIF-based auto-population of alt text and tags is also skipped.

## Privacy: Strip Location Data

The `stripLocation` setting removes all GPS and location metadata from uploaded images. When enabled, location fields are stripped from EXIF data after extraction, while preserving all other metadata (camera, lens, exposure, author, copyright, date).

This is a **global configuration setting** in `tcms.php` (not a per-field setting). It applies to all image and gallery uploads site-wide.

### Configuration

Add to your `tcms.php` file:

```php
return [
	'imageworks' => [
		'stripLocation' => true,
	],
];
```

### Fields Removed

When `stripLocation` is `true`, the following EXIF fields are removed before saving:

| Field | Source |
|-------|--------|
| `latitude` | EXIF GPS IFD |
| `longitude` | EXIF GPS IFD |
| `altitude` | EXIF GPS IFD |
| `country` | XMP / IPTC |
| `state` | XMP / IPTC |
| `city` | XMP / IPTC |
| `sublocation` | XMP / IPTC |

### When to Use

- **GDPR compliance** — Avoid storing GPS coordinates from user-uploaded images
- **Privacy protection** — Prevent accidental exposure of location data on public sites
- **Photography sites** — When location data should not be embedded in published images

### Important Notes

- **Default is `false`** — Location data is preserved by default for backward compatibility
- **Non-destructive** — The original uploaded file is not modified; only the extracted metadata stored in JSON is affected
- **Applies to new uploads only** — Existing images already saved retain their metadata

## Watermarks

Watermark settings allow you to automatically apply watermarks to images and gallery images. These settings are enforced at the image generation level and **cannot be bypassed via URL manipulation**, making them ideal for protecting photography and copyrighted content.

### Security Model

Watermark settings are enforced during image processing:
- **Cannot be removed** via URL parameters
- **Cannot be overridden** via URL parameters
- **Protects all image requests** (Twig templates, direct API access, etc.)
- **Maximum security** for photographers and content creators

### Image Watermarks
```json
{
	"watermark": {
		"mark": "logo.png",
		"markw": "200",
		"markh": "100",
		"markpad": "10",
		"markpos": "bottom-right",
		"markalpha": 80
	}
}
```

- **mark** - Path to watermark image file
- **markw** - Watermark width (pixels or percentage like "50w")
- **markh** - Watermark height (pixels or percentage)
- **markpad** - Padding from edge in pixels
- **markpos** - Position: `top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`
- **markalpha** - Transparency (0-100, where 100 is fully opaque)

### Text Watermarks
```json
{
	"watermark": {
		"marktext": "© 2024 Your Name",
		"marktextfont": "RobotoRegular",
		"marktextsize": 24,
		"marktextcolor": "ffffff",
		"marktextangle": 0,
		"marktextw": "100w",
		"marktextpad": "10",
		"marktextpos": "bottom-right",
		"marktextalpha": 80
	}
}
```

- **marktext** - Text to display as watermark
- **marktextfont** - Font name (TTF/OTF fonts from watermark-fonts depot, or "RobotoRegular" default)
- **marktextsize** - Font size in pixels
- **marktextcolor** - Text color in hex (without #)
- **marktextangle** - Rotation angle in degrees
- **marktextw** - Text width (pixels or percentage)
- **marktextpad** - Padding from edge in pixels
- **marktextpos** - Position (same options as image watermark)
- **marktextalpha** - Text transparency (0-100)

### Combined Watermarks
You can use both image and text watermarks together:

```json
{
	"watermark": {
		"mark": "logo.png",
		"markpos": "bottom-left",
		"markalpha": 70,
		"marktext": "© 2024",
		"marktextpos": "bottom-right",
		"marktextsize": 18
	}
}
```

### Dimension-Based Watermark Control

The `limit` setting allows you to apply watermarks only to images above a certain size. This is perfect for showing clean thumbnails while protecting full-size images.

```json
{
	"watermark": {
		"marktext": "© Photography Studio",
		"marktextpos": "bottom-right",
		"limit": 800
	}
}
```

#### How the Limit Works

Watermarks are applied when:
- **No limit is set** - Always apply watermark
- **No dimensions requested** (original image) - Always apply watermark
- **Requested width > limit** - Apply watermark
- **Requested height > limit** - Apply watermark
- **Both width AND height ≤ limit** - No watermark

#### Example Behavior (with limit: 800)

| Image Request | Width | Height | Watermark? |
|--------------|-------|--------|------------|
| `?w=300&h=200` | 300 | 200 | No |
| `?w=300` | 300 | auto | No |
| `?h=600` | auto | 600 | No |
| `?w=1200&h=600` | 1200 | 600 | Yes |
| `?w=600&h=1000` | 600 | 1000 | Yes |
| No parameters | Original | Original | Yes |

### Real-World Examples

#### Photography Portfolio
Small thumbnails without watermarks, full images protected:

```json
{
	"gallery": {
		"$ref": "https://www.totalcms.co/schemas/properties/gallery.json",
		"settings": {
			"watermark": {
				"marktext": "© John Doe Photography",
				"marktextpos": "bottom-right",
				"marktextsize": 20,
				"marktextcolor": "ffffff",
				"marktextalpha": 80,
				"limit": 800
			}
		}
	}
}
```

Usage in templates:
```twig
{# Thumbnail - no watermark #}
{{ cms.render.gallery(id, {w: 300, h: 200}) }}

{# Full size - watermarked #}
{{ cms.render.gallery(id, {w: 1200}, {w: 1920}) }}
```

#### Stock Photography
Centered watermark with transparency for all images:

```json
{
	"image": {
		"$ref": "https://www.totalcms.co/schemas/properties/image.json",
		"settings": {
			"watermark": {
				"mark": "watermark-logo.png",
				"markpos": "center",
				"markalpha": 50,
				"markw": "40w"
			}
		}
	}
}
```

#### E-commerce Product Images
"Sample" watermark on large product images only:

```json
{
	"image": {
		"$ref": "https://www.totalcms.co/schemas/properties/image.json",
		"settings": {
			"watermark": {
				"marktext": "SAMPLE",
				"marktextpos": "center",
				"marktextsize": 72,
				"marktextcolor": "ff0000",
				"marktextalpha": 30,
				"limit": 1000
			}
		}
	}
}
```

### Custom Watermark Fonts

To use custom fonts for text watermarks:

1. Upload TTF or OTF fonts to a depot collection (default: `watermark-fonts`)
2. Reference the font by name (with or without `.ttf` extension):

```json
{
	"watermark": {
		"marktext": "© Photography Studio",
		"marktextfont": "CustomFont",
		"marktextsize": 24
	}
}
```

Or with extension:
```json
{
	"watermark": {
		"marktextfont": "CustomFont.ttf"
	}
}
```

The system will automatically load fonts from the depot. If the font is not found, it falls back to the default RobotoRegular font.

### Watermark Notes

- **Security**: Watermark settings are enforced server-side during image generation. Users cannot bypass watermarks by manipulating URLs.
- **Limit Setting**: The `limit` setting cannot be overridden via URL parameters - it's schema-only for security.
- **Performance**: Small thumbnails below the limit threshold skip watermark processing for better performance.
- **Flexibility**: Watermark settings provide the perfect balance between user experience (clean thumbnails) and content protection (watermarked full-size images).
