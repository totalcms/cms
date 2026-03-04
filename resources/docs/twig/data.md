---
title: "Twig Data Reference"
description: "Reference for the cms.data namespace providing access to text, code, styled text, toggles, dates, numbers, URLs, emails, SVGs, colors, images, galleries, files, and depot data."
---

# cms.data

The data adapter provides typed access to object properties. Each method has sensible defaults for collection and property names, making single-collection use cases very concise.

## Raw Data Access

### raw()

Get a raw property value from any object without type coercion.

```twig
{{ cms.data.raw('blog', 'my-post', 'title') }}
{{ cms.data.raw('products', 'widget', 'price') }}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `collection` | string | Collection identifier |
| `id` | string | Object identifier |
| `property` | string | Property name |

### object()

Get a complete object from a collection. Returns an empty array if not found.

```twig
{% set post = cms.data.object('blog', 'my-post') %}
{{ post.title }}
```

### Callable Shorthand

The data adapter can be called directly as a function for backwards compatibility. This is equivalent to `raw()`.

```twig
{{ cms.data('blog', 'my-post', 'title') }}
```

## Text Content

### text()

Get text content. Default collection: `text`, default property: `text`.

```twig
{{ cms.data.text('headline') }}
{{ cms.data.text('headline', {collection: 'custom', property: 'body'}) }}
```

### code()

Get code snippet content. Default collection: `code`, default property: `code`.

```twig
{{ cms.data.code('example') }}
{{ cms.data.code('example', {collection: 'snippets', property: 'source'}) }}
```

### styledtext()

Get styled text (HTML content from a rich text editor). Default collection: `styledtext`, default property: `styledtext`.

```twig
{{ cms.data.styledtext('about')|raw }}
{{ cms.data.styledtext('about', {collection: 'pages', property: 'content'})|raw }}
```

## Simple Data Types

### toggle()

Get a boolean toggle value. Default collection: `toggle`, default property: `status`.

```twig
{% if cms.data.toggle('maintenance') %}
    <div class="maintenance-banner">Site under maintenance</div>
{% endif %}

{{ cms.data.toggle('feature', {collection: 'settings', property: 'enabled'}) }}
```

### date()

Get a date string. Default collection: `date`, default property: `date`.

```twig
{{ cms.data.date('launch') }}
{{ cms.data.date('event', {collection: 'events', property: 'eventDate'}) }}
```

### number()

Get a number value (returned as string). Default collection: `number`, default property: `number`.

```twig
{{ cms.data.number('visitors') }}
{{ cms.data.number('price', {collection: 'products', property: 'cost'}) }}
```

### url()

Get a URL string. Default collection: `url`, default property: `url`.

```twig
<a href="{{ cms.data.url('website') }}">Visit Site</a>
{{ cms.data.url('social', {collection: 'links', property: 'href'}) }}
```

### email()

Get an email address. Default collection: `email`, default property: `email`. Supports HTML obfuscation for anti-spam.

```twig
{# Plain email #}
{{ cms.data.email('contact') }}

{# HTML-obfuscated email (anti-spam) #}
{{ cms.data.email('contact', {}, true)|raw }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | string | required | Object identifier |
| `options` | array | `[]` | Options with `collection` and `property` keys |
| `obfuscate` | bool | `false` | HTML-encode the email for anti-spam protection |

### svg()

Get SVG content. Default collection: `svg`, default property: `svg`.

```twig
{{ cms.data.svg('logo')|raw }}
{{ cms.data.svg('icon', {collection: 'icons', property: 'svgData'})|raw }}
```

## Colors

### color() / colour()

Get color data as an array with `hex` and `oklch` properties. `colour()` is a British spelling alias.

```twig
{% set myColor = cms.data.color('brand') %}
{% set myColor = cms.data.colour('brand') %}  {# Same thing #}

{# Access color values #}
{{ myColor.hex }}         {# #ff0000 #}
{{ myColor.oklch.l }}     {# Lightness #}
{{ myColor.oklch.c }}     {# Chroma #}
{{ myColor.oklch.h }}     {# Hue #}

{# Use with color filters #}
{{ myColor|hex }}         {# #ff0000 #}
{{ myColor|oklch }}       {# oklch(62.8% 0.25768 29.234) #}
{{ myColor|rgb }}         {# rgb(255 0 0) #}
{{ myColor|hsl }}         {# hsl(0 100% 50%) #}
```

Default collection: `color`, default property: `color`.

## Complex Data Types

### image()

Get image data as an array. Default collection: `image`, default property: `image`.

```twig
{% set img = cms.data.image('hero') %}
{{ img.filename }}
{{ img.alt }}
{{ img.width }}
{{ img.height }}
```

For rendering images as HTML, see [cms.render.image()](docs/twig/render).

### gallery()

Get gallery data as an array of image objects. Default collection: `gallery`, default property: `gallery`.

```twig
{% set photos = cms.data.gallery('vacation') %}
{% for photo in photos %}
    <p>{{ photo.name }} — {{ photo.alt }}</p>
{% endfor %}
```

For rendering galleries with lightbox support, see [cms.render.gallery()](docs/twig/render).

### file()

Get file data as an array. Default collection: `file`, default property: `file`.

```twig
{% set doc = cms.data.file('report') %}
{{ doc.filename }}
{{ doc.size }}
{{ doc.mime }}
```

For generating download/stream URLs, see [cms.media](docs/twig/media).

### depot()

Get depot (multi-file storage) data as an array. Default collection: `depot`, default property: `depot`.

```twig
{% set files = cms.data.depot('documents') %}
{% for file in files %}
    <p>{{ file.name }} ({{ file.size }} bytes)</p>
{% endfor %}
```

For generating download/stream URLs, see [cms.media](docs/twig/media).

## Options Pattern

All typed data methods accept an `options` array as the second parameter with these keys:

| Key | Type | Description |
|-----|------|-------------|
| `collection` | string | Override the default collection |
| `property` | string | Override the default property |

```twig
{# Using defaults #}
{{ cms.data.text('headline') }}

{# Custom collection and property #}
{{ cms.data.text('headline', {collection: 'pages', property: 'title'}) }}
```
