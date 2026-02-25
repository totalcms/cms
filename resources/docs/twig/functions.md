# Twig Functions Reference

Total CMS provides powerful custom Twig functions for form handling, string manipulation, media embedding, and more. This reference includes practical usage examples for each function.

## Form and UI Functions

### `selectOptions(array $options, string $label, string $value): array`
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
{% set categories = cms.collection.objects('categories') | map(c => c.name) %}
<select name="category">
    {% for option in selectOptions(categories) %}
        <option value="{{ option.value }}" {{ option.value == selectedCategory ? 'selected' : '' }}>
            {{ option.label }}
        </option>
    {% endfor %}
</select>

{# Using with custom label and value keys #}
{% set users = cms.collection.objects('users') %}
{% set userOptions = selectOptions(users, 'name', 'id') %}
<select name="author">
    {% for option in userOptions %}
        <option value="{{ option.value }}">{{ option.label }}</option>
    {% endfor %}
</select>
```

## Math Functions

### `floor(number): int`
Rounds a number down to the nearest integer.

```twig
{{ floor(4.7) }} {# Output: 4 #}
{{ floor(9.99) }} {# Output: 9 #}

{# Calculate pages #}
{% set totalItems = 47 %}
{% set perPage = 10 %}
{% set fullPages = floor(totalItems / perPage) %}
```

### `ceil(number): int`
Rounds a number up to the nearest integer.

```twig
{{ ceil(4.1) }} {# Output: 5 #}
{{ ceil(9.01) }} {# Output: 10 #}

{# Calculate total pages needed #}
{% set totalItems = 47 %}
{% set perPage = 10 %}
{% set totalPages = ceil(totalItems / perPage) %}
```

## String Functions

### `addslashes(string): string`
Escapes special characters with backslashes.

```twig
{% set text = "He said \"Hello\"" %}
{{ addslashes(text) }} {# Output: He said \"Hello\" #}
```

### `chunk_split(string, length, separator): string`
Splits a string into smaller chunks.

```twig
{% set serial = "ABCD1234EFGH5678" %}
{{ chunk_split(serial, 4, '-') }} {# Output: ABCD-1234-EFGH-5678- #}
```

### `explode(delimiter, string): array`
Splits a string into an array by a delimiter.

```twig
{% set tags = "php,javascript,python" %}
{% set tagArray = explode(',', tags) %}
{% for tag in tagArray %}
    <span class="tag">{{ tag }}</span>
{% endfor %}
```

### `str_pad(string, length, pad_string, pad_type): string`
Pads a string to a certain length.

```twig
{{ str_pad('42', 5, '0', 0) }} {# Output: 00042 (STR_PAD_LEFT = 0) #}
{{ str_pad('42', 5, '0', 1) }} {# Output: 42000 (STR_PAD_RIGHT = 1) #}
```

### `strlen(string): int`
Returns the length of a string.

```twig
{{ strlen('Hello World') }} {# Output: 11 #}

{% if strlen(post.excerpt) > 200 %}
    {{ post.excerpt | slice(0, 200) }}...
{% else %}
    {{ post.excerpt }}
{% endif %}
```

### `indexOf(haystack, needle, offset): int|false`
Finds the position of the first occurrence of a substring. Alias for `strpos`.

```twig
{{ indexOf('Hello World', 'World') }} {# Output: 6 #}

{% if indexOf(email, '@') %}
    Valid email format
{% endif %}

{# With offset - start searching from position 5 #}
{{ indexOf('Hello Hello', 'Hello', 5) }} {# Output: 6 #}
```

### `lastIndexOf(haystack, needle, offset): int|false`
Finds the position of the last occurrence of a substring. Alias for `strrpos`.

```twig
{% set filename = "document.backup.pdf" %}
{% set lastDot = lastIndexOf(filename, '.') %}
{# lastDot = 15 #}

{# Get file extension #}
{% set ext = filename | slice(lastIndexOf(filename, '.') + 1) %}
{# ext = "pdf" #}
```

### `wordwrap(string, width, break, cut): string`
Wraps a string to a given number of characters.

```twig
{% set longText = "This is a very long line that needs to be wrapped" %}
{{ wordwrap(longText, 20, '<br>', false) }}
```

### `similar_text(string1, string2): int`
Returns the number of matching characters between two strings.

```twig
{% set similarity = similar_text('Hello', 'Hallo') %}
{# similarity = 4 #}
```

## String Testing Functions

### `contains(string, substring): bool`
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
{% for post in cms.collection.objects('blog') %}
    {% if contains(post.content | lower, 'tutorial') %}
        <article class="tutorial-post">
            <span class="badge">Tutorial</span>
            <h2>{{ post.title }}</h2>
        </article>
    {% endif %}
{% endfor %}

{# Check for external links #}
{% for link in cms.collection.objects('links') %}
    <a href="{{ link.url }}"
       {{ contains(link.url, 'http') ? 'target="_blank" rel="noopener"' : '' }}>
        {{ link.title }}
        {% if contains(link.url, 'http') %}
            <span class="external-icon">↗</span>
        {% endif %}
    </a>
{% endfor %}
```

### `startsWith(string, prefix): bool`
Checks if a string starts with a specific substring.

```twig
{# Protocol-aware link handling #}
{% for link in cms.collection.objects('social-links') %}
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
```

### `endsWith(string, suffix): bool`
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
{% for link in cms.collection.objects('resources') %}
    <a href="{{ link.url }}"
       {{ endsWith(link.url, '.pdf') ? 'download' : '' }}>
        {{ link.title }}
        {% if endsWith(link.url, '.pdf') %}
            <span class="download-icon">⬇</span>
        {% endif %}
    </a>
{% endfor %}
```

## Hashing Functions

### `md5(string): string`
Generates an MD5 hash of a string.

```twig
{{ md5('hello@example.com') }}
{# Useful for Gravatar URLs #}
<img src="https://www.gravatar.com/avatar/{{ md5(user.email | lower | trim) }}">
```

### `sha1(string): string`
Generates a SHA1 hash of a string.

```twig
{{ sha1('some-data') }}
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
{% for product in cms.collection.objects('products') %}
    {% set modalId = uniqid() %}
    <button data-modal="#modal-{{ modalId }}">View {{ product.name }}</button>
    <div id="modal-{{ modalId }}" class="modal">
        <h2>{{ product.name }}</h2>
        <p>{{ product.description }}</p>
    </div>
{% endfor %}
```

### `buildQuery(array): string`
Generates a URL-encoded query string from an array. Alias for `http_build_query`.

```twig
{% set params = {page: 2, sort: 'date', order: 'desc'} %}
<a href="/blog?{{ buildQuery(params) }}">Page 2</a>
{# Output: /blog?page=2&sort=date&order=desc #}

{# Build search URL with filters #}
{% set filters = {category: 'tech', author: 'joe', featured: true} %}
<a href="/search?{{ buildQuery(filters) }}">View Results</a>
```

## Type Checking Functions

### `typeof(variable): string`
Returns the type of a variable as a string. Alias for `gettype`.

```twig
{{ typeof('hello') }}   {# Output: string #}
{{ typeof(42) }}        {# Output: integer #}
{{ typeof([1, 2, 3]) }} {# Output: array #}
{{ typeof(true) }}      {# Output: boolean #}
{{ typeof(null) }}      {# Output: NULL #}

{# Conditional rendering based on type #}
{% if typeof(value) == 'array' %}
    {% for item in value %}{{ item }}{% endfor %}
{% else %}
    {{ value }}
{% endif %}
```

### `istype(variable, type): bool`
Checks if a variable is of a specific type.

```twig
{# Safe data handling #}
{% set config = cms.collection.objects('settings', 'site-config') %}

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

## Array Sorting Functions

### `ksort(array): array`
Sorts an array by key in ascending order.

```twig
{% set data = {z: 'last', a: 'first', m: 'middle'} %}
{% set sorted = ksort(data) %}
{# Result: {a: 'first', m: 'middle', z: 'last'} #}
```

### `krsort(array): array`
Sorts an array by key in descending order.

```twig
{% set data = {a: 'first', m: 'middle', z: 'last'} %}
{% set sorted = krsort(data) %}
{# Result: {z: 'last', m: 'middle', a: 'first'} #}
```

### `sortByKey(array, key): array`
Sorts an array of objects/arrays by a specific key.

```twig
{% set products = cms.collection.objects('products') %}
{% set sortedByPrice = sortByKey(products, 'price') %}

{% for product in sortedByPrice %}
    <div class="product">
        <h3>{{ product.name }}</h3>
        <span class="price">${{ product.price }}</span>
    </div>
{% endfor %}

{# Sort users by name #}
{% set users = sortByKey(cms.collection.objects('users'), 'name') %}
```

## Debugging Functions

### `print_r(variable): string`
Pretty-prints variables for debugging.

```twig
{% if app.debug %}
    <details>
        <summary>Debug: Post Data</summary>
        {{ print_r(post) }}
    </details>
{% endif %}
```

### `var_dump(variable): string`
Detailed variable dump with type information.

```twig
{% if app.debug %}
    <div class="debug-dump">
        {{ var_dump(complexObject) }}
    </div>
{% endif %}
```

### `json_pretty(variable): string`
Outputs a variable as formatted JSON.

```twig
{% if cms.config('debug') %}
    <pre>{{ json_pretty(data) }}</pre>
{% endif %}

{# Output:
{
    "name": "John",
    "email": "john@example.com",
    "active": true
}
#}
```

### `parseJson(string): array`
Decodes JSON strings to arrays. Alias for `json_decode`.

```twig
{# Process stored JSON configuration #}
{% set settings = cms.collection.object('config', 'app-settings') %}
{% set config = parseJson(settings.json_config) %}

<div class="app-config">
    {% for key, value in config %}
        <div class="config-item">
            <strong>{{ key | humanize }}:</strong> {{ value }}
        </div>
    {% endfor %}
</div>

{# Parse API response #}
{% set data = parseJson(apiResponse) %}
{% if data %}
    <h2>{{ data.title }}</h2>
    <p>{{ data.description }}</p>
{% endif %}
```

## File System Functions

### `imageExists(image): bool`
Checks if an image file exists.

```twig
{# Safe image display with fallbacks #}
{% set product = cms.collection.object('product', 'smartphone') %}

{% if imageExists(product.image) %}
    <img src="{{ product.image.url }}" alt="{{ product.image.alt }}">
{% else %}
    <img src="/assets/placeholder-product.jpg" alt="Product image unavailable">
{% endif %}

{# Gallery with existence check #}
{% set gallery = cms.collection.object('gallery', 'portfolio') %}
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

### `embed(url, options)`
Auto-detects and embeds various media types.

```twig
{# Auto-embed various media types #}
{% for media in cms.collection.objects('media-links') %}
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
{% set post = cms.collection.object('blog', 'video-tutorial') %}
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

### `youtube(url, options)`
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
```

### `vimeo(url, options)`
Embeds Vimeo videos with specific options.

```twig
{# Vimeo video gallery #}
{% set videos = cms.collection.objects('video-gallery') %}
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
```

### `video(url, options)`
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
</section>
```

### `audio(url, options)`
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
```

## Access Control Functions

Total CMS provides comprehensive access control functions for checking user permissions in templates. These allow you to conditionally show/hide UI elements based on the current user's access groups.

### Quick Reference

```twig
{# Collections #}
{% if cms.canAccessCollection('blog', 'GET') %}...{% endif %}
{% if cms.canAccessCollectionsMethod('POST') %}...{% endif %}

{# Schemas #}
{% if cms.canAccessSchema('blog', 'GET') %}...{% endif %}
{% if cms.canAccessSchemasMethod('POST') %}...{% endif %}

{# Templates #}
{% if cms.canAccessTemplatesMethod('GET') %}...{% endif %}

{# Settings #}
{% if cms.canAccessSetting('cache', 'GET') %}...{% endif %}
{% if cms.canAccessSettingsMethod('POST') %}...{% endif %}

{# Utils #}
{% if cms.canAccessUtil('cache-manager', 'GET') %}...{% endif %}
{% if cms.canAccessUtilsMethod('POST') %}...{% endif %}

{# Boolean permissions #}
{% if cms.canAccessMailer() %}...{% endif %}
{% if cms.canAccessPlayground() %}...{% endif %}
{% if cms.canAccessDocs() %}...{% endif %}

{# Admin check #}
{% if cms.isAdmin() %}...{% endif %}
```

For complete documentation including practical examples, access group configuration, and best practices, see the [Access Groups Documentation](docs/auth/access-groups).

## Related Documentation

- [Access Groups](docs/auth/access-groups) - Complete access control documentation
- [Twig Variables](docs/twig/variables) - Available variables in templates
- [Twig Filters](docs/twig/filters) - Available filters for data transformation
- [CMS Grid Tag](docs/twig/cmsgrid-tag) - Grid rendering system

Remember: These functions help you create dynamic, robust templates that handle various data types and conditions gracefully!
