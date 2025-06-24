# Twig Functions Reference

Total CMS provides powerful custom Twig functions for form handling, string manipulation, media embedding, and more. This reference includes practical usage examples for each function.

## Form and UI Functions

### `selectOptions(array $options): array`
Converts simple arrays to proper select option format.

```twig
{# Simple array to select options #}
{% set categories = ['Technology', 'Design', 'Marketing'] %}
{% set selectOptions = selectOptions(categories) %}

<select name="category">
    {% for option in selectOptions %}
        <option value="{{ option.value }}">{{ option.label }}</option>
    {% endfor %}
</select>

{# Dynamic categories from collection #}
{% set categories = cms.objects('categories') | map(c => c.name) %}
<select name="category">
    {% for option in selectOptions(categories) %}
        <option value="{{ option.value }}" {{ option.value == selectedCategory ? 'selected' : '' }}>
            {{ option.label }}
        </option>
    {% endfor %}
</select>

{# Using with form field #}
{% set statusOptions = selectOptions(['draft', 'published', 'archived']) %}
{{ formSelect('status', statusOptions, post.status) }}
```

## Utility Functions

### `uniqid(): string`
Generates unique identifiers for HTML elements and temporary values.

```twig
{# Unique form field IDs #}
{% set fieldId = uniqid() %}
<label for="email-{{ fieldId }}">Email Address</label>
<input type="email" id="email-{{ fieldId }}" name="email">

{# Unique modal IDs #}
{% for product in cms.objects('products') %}
    {% set modalId = uniqid() %}
    <button data-modal="#modal-{{ modalId }}">View {{ product.name }}</button>
    <div id="modal-{{ modalId }}" class="modal">
        <h2>{{ product.name }}</h2>
        <p>{{ product.description }}</p>
    </div>
{% endfor %}

{# Temporary file names #}
{% set tempId = uniqid() %}
<input type="hidden" name="temp_upload_id" value="{{ tempId }}">

{# CSS animation delays #}
{% for item in gallery.images %}
    <div class="image-{{ uniqid() }}" style="animation-delay: {{ loop.index * 0.1 }}s;">
        <img src="{{ item.url }}" alt="{{ item.alt }}">
    </div>
{% endfor %}
```

## String Testing Functions

### `contains(string $string, string $contains): bool`
Checks if a string contains a substring.

```twig
{# Check file types #}
{% set document = cms.file('manual') %}
{% if contains(document.file, '.pdf') %}
    <span class="file-type pdf">PDF Document</span>
{% elseif contains(document.file, '.doc') %}
    <span class="file-type doc">Word Document</span>
{% endif %}

{# Filter posts by content #}
{% for post in cms.objects('blog') %}
    {% if contains(post.content | lower, 'tutorial') %}
        <article class="tutorial-post">
            <span class="badge">Tutorial</span>
            <h2>{{ post.title }}</h2>
        </article>
    {% endif %}
{% endfor %}

{# Check for external links #}
{% for link in cms.objects('links') %}
    <a href="{{ link.url }}"
       {{ contains(link.url, 'http') ? 'target="_blank" rel="noopener"' : '' }}>
        {{ link.title }}
        {% if contains(link.url, 'http') %}
            <span class="external-icon">↗</span>
        {% endif %}
    </a>
{% endfor %}

{# Email validation #}
{% if not contains(user.email, '@') %}
    <span class="error">Invalid email address</span>
{% endif %}
```

### `startsWith(string $string, string $starts): bool`
Checks if a string starts with a specific substring.

```twig
{# Protocol-aware link handling #}
{% for link in cms.objects('social-links') %}
    {% if startsWith(link.url, 'http') %}
        <a href="{{ link.url }}" target="_blank">{{ link.title }}</a>
    {% else %}
        <a href="https://{{ link.url }}" target="_blank">{{ link.title }}</a>
    {% endif %}
{% endfor %}

{# File path handling #}
{% set upload = cms.file('document') %}
{% if startsWith(upload.file, '/') %}
    <a href="{{ upload.file }}">Download</a>
{% else %}
    <a href="/uploads/{{ upload.file }}">Download</a>
{% endif %}

{# CSS class conditionals #}
{% for page in navigation %}
    <a href="{{ page.url }}"
       class="{{ startsWith(page.url, '/admin') ? 'admin-link' : 'public-link' }}">
        {{ page.title }}
    </a>
{% endfor %}

{# Phone number formatting #}
{% if startsWith(contact.phone, '+1') %}
    {{ contact.phone | formatPhone('US') }}
{% else %}
    +1{{ contact.phone | formatPhone('US') }}
{% endif %}
```

### `endsWith(string $string, string $ends): bool`
Checks if a string ends with a specific substring.

```twig
{# File extension detection #}
{% for file in cms.depot('downloads') %}
    <div class="file-item">
        {% if endsWith(file.filename | lower, '.pdf') %}
            <i class="icon-pdf"></i>
        {% elseif endsWith(file.filename | lower, '.jpg') or endsWith(file.filename | lower, '.png') %}
            <i class="icon-image"></i>
        {% elseif endsWith(file.filename | lower, '.zip') %}
            <i class="icon-archive"></i>
        {% else %}
            <i class="icon-file"></i>
        {% endif %}
        <span>{{ file.filename }}</span>
    </div>
{% endfor %}

{# URL handling #}
{% for link in cms.objects('resources') %}
    <a href="{{ link.url }}"
       {{ endsWith(link.url, '.pdf') ? 'download' : '' }}>
        {{ link.title }}
        {% if endsWith(link.url, '.pdf') %}
            <span class="download-icon">⬇</span>
        {% endif %}
    </a>
{% endfor %}

{# Image format detection #}
{% set hero = cms.image('hero-banner') %}
{% if endsWith(hero.image | lower, '.webp') %}
    {# Modern format available #}
    <img src="{{ hero.image }}" alt="{{ hero.alt }}">
{% else %}
    {# Convert to WebP #}
    <img src="{{ hero.image | webp }}" alt="{{ hero.alt }}">
{% endif %}
```

## Type Checking Functions

### `istype(mixed $variable, string $type): bool`
Checks if a variable is of a specific type.

```twig
{# Safe data handling #}
{% set config = cms.objects('settings', 'site-config') %}

{% if istype(config.menu, 'array') %}
    <nav>
        {% for item in config.menu %}
            <a href="{{ item.url }}">{{ item.title }}</a>
        {% endfor %}
    </nav>
{% endif %}

{# Form field handling #}
{% for field in schema.properties %}
    {% if istype(field.default, 'string') %}
        <input type="text" name="{{ field.name }}" value="{{ field.default }}">
    {% elseif istype(field.default, 'array') %}
        <select name="{{ field.name }}[]" multiple>
            {% for option in field.default %}
                <option value="{{ option }}">{{ option }}</option>
            {% endfor %}
        </select>
    {% elseif istype(field.default, 'boolean') %}
        <input type="checkbox" name="{{ field.name }}" {{ field.default ? 'checked' : '' }}>
    {% endif %}
{% endfor %}
```

## Debugging Functions

### `print_r(mixed $variable): string`
Pretty-prints variables for debugging.

```twig
{% if app.debug %}
    <details>
        <summary>Debug: Post Data</summary>
        <pre>{{ print_r(post) }}</pre>
    </details>

    <details>
        <summary>Debug: Request Data</summary>
        <pre>{{ print_r(app.request) }}</pre>
    </details>
{% endif %}

{# Development mode data inspection #}
{% if config.env == 'development' %}
    <div class="debug-panel">
        <h3>Template Variables</h3>
        <pre>{{ print_r({
            'post': post,
            'user': app.user,
            'config': config
        }) }}</pre>
    </div>
{% endif %}
```

### `var_dump(mixed $variable): string`
Detailed variable dump with type information.

```twig
{% if app.debug %}
    <div class="debug-dump">
        {{ var_dump(complexObject) }}
    </div>
{% endif %}

{# API debugging #}
{% if config.api.debug %}
    <script>
        console.log({{ var_dump(apiResponse) | raw }});
    </script>
{% endif %}
```

### `json_decode(mixed $variable): array`
Decodes JSON strings to arrays.

```twig
{# Process stored JSON configuration #}
{% set settings = cms.object('config', 'app-settings') %}
{% set config = json_decode(settings.json_config) %}

<div class="app-config">
    {% for key, value in config %}
        <div class="config-item">
            <strong>{{ key | humanize }}:</strong> {{ value }}
        </div>
    {% endfor %}
</div>

{# Parse JSON metadata #}
{% set product = cms.object('product', 'laptop') %}
{% set features = json_decode(product.features_json) %}

<ul class="features">
    {% for feature in features %}
        <li>{{ feature.name }}: {{ feature.value }}</li>
    {% endfor %}
</ul>
```

## File System Functions

### `imageExists(image): bool`
Checks if an image file exists.

```twig
{# Safe image display with fallbacks #}
{% set product = cms.object('product', 'smartphone') %}

{% if imageExists(product.image) %}
    <img src="{{ product.image.url }}" alt="{{ product.image.alt }}">
{% else %}
    <img src="/assets/placeholder-product.jpg" alt="Product image unavailable">
{% endif %}

{# Gallery with existence check #}
{% set gallery = cms.object('gallery', 'portfolio') %}
<div class="gallery">
    {% for image in gallery.images %}
        {% if imageExists(image) %}
            <figure>
                <img src="{{ image.url | resize(400, 300) }}" alt="{{ image.alt }}">
                <figcaption>{{ image.caption }}</figcaption>
            </figure>
        {% endif %}
    {% endfor %}
</div>

{# Profile picture with fallback #}
{% set user = cms.object('users', 'joeworkman') %}
<div class="profile">
    {% if user.avatar and imageExists(user.avatar) %}
        <img src="{{ user.avatar.url }}" alt="{{ user.name }}" class="avatar">
    {% else %}
        <div class="avatar-placeholder">{{ user.name | slice(0, 1) }}</div>
    {% endif %}
</div>
```

### `fileExists(file): bool`
Checks if a file exists.

```twig
{# Download links with existence check #}
{% set manual = cms.file('user-manual') %}

{% if fileExists(manual) %}
    <a href="{{ manual.file }}" download class="download-btn">
        Download Manual ({{ manual.file | filesize }})
    </a>
{% else %}
    <span class="unavailable">Manual currently unavailable</span>
{% endif %}

{# Resource listing #}
{% set resources = cms.depot('downloads') %}
<ul class="resources">
    {% for resource in resources.files %}
        {% if fileExists(resource) %}
            <li>
                <a href="{{ resource.url }}" download>{{ resource.filename }}</a>
                <span class="size">{{ resource.url | filesize }}</span>
            </li>
        {% endif %}
    {% endfor %}
</ul>
```

### `svgSymbol(id): string`
Creates an SVG element that references a symbol defined in an SVG sprite.

```twig
{# Basic icon usage #}
{{ svgSymbol('icon-home') }}
{# Outputs: <svg><use href="#icon-home"></use></svg> #}

{# Navigation with icons #}
<nav class="main-nav">
    <a href="/">{{ svgSymbol('icon-home') }} Home</a>
    <a href="/about">{{ svgSymbol('icon-info') }} About</a>
    <a href="/contact">{{ svgSymbol('icon-mail') }} Contact</a>
</nav>
```

**SVG Sprite Setup:**
To use this function, define your SVG symbols in a sprite:

```html
<!-- Place this in your template, typically hidden. -->
<!-- Use the svgSymbol filter as well. -->
<svg style="display: none;">
	{{ cms.svg('home') | svgToSymbol('icon-home') }}
	{{ cms.svg('mail') | svgToSymbol('icon-mail') }}
</svg>
```

## Media Embedding Functions

### `embed(url, array options)`
Auto-detects and embeds various media types.

```twig
{# Auto-embed various media types #}
{% for media in cms.objects('media-links') %}
    <div class="media-embed">
        {{ embed(media.url, {
            width: 800,
            height: 450,
            autoplay: false,
            responsive: true
        }) }}
    </div>
{% endfor %}

{# Blog post with media embeds #}
{% set post = cms.object('blog', 'video-tutorial') %}
<article>
    <h1>{{ post.title }}</h1>

    {% if post.video_url %}
        <div class="post-media">
            {{ embed(post.video_url, {
                width: '100%',
                height: 315,
                autoplay: false
            }) }}
        </div>
    {% endif %}

    <div class="content">{{ post.content | markdown }}</div>
</article>
```

### `vimeo(url, array options)`
Embeds Vimeo videos with specific options.

```twig
{# Vimeo video gallery #}
{% set videos = cms.objects('video-gallery') %}
<div class="video-grid">
    {% for video in videos %}
        <div class="video-item">
            <h3>{{ video.title }}</h3>
            {{ vimeo(video.vimeo_url, {
                width: 560,
                height: 315,
                autoplay: false,
                loop: false,
                portrait: false,
                title: false,
                byline: false,
                dnt: true
            }) }}
        </div>
    {% endfor %}
</div>

{# Hero video background #}
{% set hero = cms.object('hero', 'homepage') %}
<section class="hero">
    {{ vimeo(hero.background_video, {
        width: '100%',
        height: '100%',
        autoplay: true,
        loop: true,
        muted: true,
        controls: false,
        background: true
    }) }}
</section>
```

### `youtube(url, array options)`
Embeds YouTube videos with specific options.

```twig
{# YouTube playlist #}
<div class="playlist">
    {% for video in playlist.videos %}
        <div class="video-card">
            <h4>{{ video.title }}</h4>
            {{ youtube(video.youtube_url, {
                width: 560,
                height: 315,
                rel: 0,
                showinfo: 0,
                autoplay: 0,
                modestbranding: 1,
                privacy_enhanced: true
            }) }}
            <p>{{ video.description }}</p>
        </div>
    {% endfor %}
</div>

{# Responsive YouTube embed #}
{% set tutorial = cms.object('tutorial', 'getting-started') %}
<div class="video-wrapper">
    {{ youtube(tutorial.video_url, {
        width: '100%',
        height: 'auto',
        responsive: true,
        privacy_enhanced: true
    }) }}
</div>
```

### `audio(url, array attrs)`
Creates HTML5 audio players.

```twig
{# Podcast episode player #}
<div class="podcast-player">
    <h2>{{ episode.title }}</h2>
    {{ audio(episode.audio_file, {
        controls: true,
        preload: 'metadata',
        class: 'podcast-audio'
    }) }}
    <p>{{ episode.description }}</p>
</div>

{# Music gallery #}
<div class="music-library">
    {% for track in tracks %}
        <div class="track">
            <h3>{{ track.title }}</h3>
            <p>{{ track.artist }}</p>
            {{ audio(track.file, {
                controls: true,
                preload: 'none',
                class: 'track-player'
            }) }}
        </div>
    {% endfor %}
</div>
```

### `video(url, array attrs)`
Creates HTML5 video players.

```twig
{# Video testimonials #}
<div class="testimonials">
    {% for testimonial in testimonials %}
        <div class="testimonial">
            {{ video(testimonial.video_file, {
                width: 400,
                height: 300,
                controls: true,
                poster: testimonial.thumbnail,
                preload: 'metadata'
            }) }}
            <h3>{{ testimonial.client_name }}</h3>
        </div>
    {% endfor %}
</div>

{# Hero background video #}
<section class="hero-video">
    {{ video(hero.background_video, {
        width: '100%',
        height: '100%',
        autoplay: true,
        muted: true,
        loop: true,
        controls: false,
        class: 'hero-bg-video'
    }) }}
    <div class="hero-content">
        <h1>{{ hero.title }}</h1>
        <p>{{ hero.subtitle }}</p>
    </div>
</section>
```

### `iframe(url)`
Creates iframe embeds for external content.

```twig
{# External form embed #}
<div class="contact-section">
    <h2>Contact Us</h2>
    {{ iframe(contact.form_url) }}
</div>

{# Map embed #}
<div class="map-container">
    {{ iframe(location.map_embed_url) }}
</div>

{# Social media feeds #}
{% for social in cms.objects('social-feeds') %}
    <div class="social-embed">
        <h3>{{ social.platform | title }}</h3>
        {{ iframe(social.embed_url) }}
    </div>
{% endfor %}
```

## Real-World Examples

### Dynamic Navigation with File Checks

```twig
<nav class="main-navigation">
    {% for page in cms.objects('navigation') %}
        {% if page.type == 'link' %}
            <a href="{{ page.url }}"
               {{ startsWith(page.url, 'http') ? 'target="_blank"' : '' }}>
                {{ page.title }}
            </a>
        {% elseif page.type == 'file' and fileExists(page.file) %}
            <a href="{{ page.file.url }}" download>{{ page.title }}</a>
        {% endif %}
    {% endfor %}
</nav>
```

### Smart Media Gallery

```twig
{% set gallery = cms.objects('gallery', 'portfolio') %}
<div class="media-gallery">
    {% for item in gallery.items %}
        {% set itemId = uniqid() %}
        <div class="gallery-item" id="item-{{ itemId }}">
            {% if contains(item.url, 'youtube.com') %}
                {{ youtube(item.url, {width: 560, height: 315}) }}
            {% elseif contains(item.url, 'vimeo.com') %}
                {{ vimeo(item.url, {width: 560, height: 315}) }}
            {% elseif endsWith(item.url, '.mp4') %}
                {{ video(item.url, {controls: true, width: 560}) }}
            {% elseif imageExists(item) %}
                <img src="{{ item.url }}" alt="{{ item.alt }}">
            {% else %}
                <div class="placeholder">Media not available</div>
            {% endif %}
        </div>
    {% endfor %}
</div>
```

Remember: These functions help you create dynamic, robust templates that handle various data types and conditions gracefully!