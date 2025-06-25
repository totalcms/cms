# Twig Filters Reference

Total CMS extends Twig with powerful custom filters for text processing, color manipulation, array handling, and more. This reference includes usage examples for each filter.

## Text Filters

### String Manipulation

#### `humanize(string $slug, string $sep = '-'): string`
Converts slugs to human-readable text.

```twig
{{ "hello-world" | humanize }}
{# Output: Hello World #}

{{ "first_name" | humanize('_') }}
{# Output: First Name #}
```

#### `titleize(string $slug, string $sep = '-'): string`
Converts to title case with proper capitalization.

```twig
{{ "the-quick-brown-fox" | titleize }}
{# Output: The Quick Brown Fox #}

{{ "api_response_data" | titleize('_') }}
{# Output: API Response Data #}
```

#### `basename(string $file): string`
Extracts filename from path.

```twig
{{ "/path/to/document.pdf" | basename }}
{# Output: document.pdf #}

{% set upload = cms.file('manual') %}
<p>Download: {{ upload.file | basename }}</p>
```

#### `dirname(string $file): string`
Extracts directory from path.

```twig
{{ "/var/www/uploads/image.jpg" | dirname }}
{# Output: /var/www/uploads #}
```

### String Trimming

#### `rtrim(string $string): string`
Removes whitespace from the right side.

```twig
{{ "  Hello World  " | rtrim }}
{# Output: "  Hello World" #}
```

#### `ltrim(string $string): string`
Removes whitespace from the left side.

```twig
{{ "  Hello World  " | ltrim }}
{# Output: "Hello World  " #}
```

### Text Truncation

#### `truncate(string $string, int $length, bool $keepWords = false): string`
Truncates text to specified length.

```twig
{% set post = cms.object('blog', 'my-post') %}

{# Character-based truncation #}
{{ post.content | truncate(100) }}

{# Word-safe truncation #}
{{ post.content | truncate(100, true) }}

{# Usage in blog listing #}
{% for post in cms.objects('blog') %}
    <article>
        <h2>{{ post.title }}</h2>
        <p>{{ post.excerpt | truncate(150, true) }}...</p>
    </article>
{% endfor %}
```

#### `truncateWords(string $string, int $length): string`
Truncates by word count instead of characters.

```twig
{{ post.content | truncateWords(25) }}
{# Truncates to first 25 words #}

{# Perfect for excerpts #}
<p class="excerpt">{{ post.content | truncateWords(30) }}...</p>
```

### Text Analysis

#### `charcount(string $text): int`
Counts characters in text.

```twig
{% set content = cms.text('about-us') %}
<p>About us ({{ content.text | charcount }} characters)</p>

{# Validation in forms #}
{% if post.title | charcount > 100 %}
    <span class="error">Title too long</span>
{% endif %}
```

#### `wordcount(string $text): int`
Counts words in text.

```twig
{{ post.content | wordcount }} words

{# Reading time calculation #}
{% set words = post.content | wordcount %}
<time>{{ (words / 200) | round }} min read</time>
```

#### `readtime(string $text, int $wpm = 180): float`
Calculates reading time in minutes.

```twig
{{ post.content | readtime }} min read
{{ post.content | readtime(250) }} min read (fast reader)

{# Display reading time #}
<div class="meta">
    <span>{{ post.content | readtime | round }} minute read</span>
</div>
```

### Specialized Text Processing

#### `digitsOnly(string $text): string`
Extracts only digits from text.

```twig
{{ "Phone: (555) 123-4567" | digitsOnly }}
{# Output: 5551234567 #}

{# Clean phone numbers for tel: links #}
<a href="tel:{{ contact.phone | digitsOnly }}">
    {{ contact.phone | formatPhone }}
</a>
```

#### `formatPhone(string $string, string $countryCode = 'US'): string`
Formats phone numbers by country.

```twig
{{ "5551234567" | formatPhone }}
{# Output: (555) 123-4567 #}

{{ "5551234567" | formatPhone('CA') }}
{# Output: (555) 123-4567 #}

{# Format contact information #}
{% for contact in api('contact') %}
    <p>{{ contact.name }}: {{ contact.phone | formatPhone }}</p>
{% endfor %}
```

#### `svgToSymbol(string $svg, string $symbolId): string`
Converts SVG to symbol for icon systems.

```twig
{% set icon = cms.svg('home-icon') %}
{{ icon.svg | svgToSymbol('home') }}

{# Create icon library #}
<svg style="display: none;">
    {% for icon in cms.objects('svg') %}
        {{ icon.content | svgToSymbol(icon.id) }}
    {% endfor %}
</svg>

{# Use icons #}
<svg><use href="#home"></use></svg>
```

## Color Filters

### Color Conversion

#### `hexToColor(string $hex): array`
Converts hex color to color array.

```twig
{% set brand = "#3498db" | hexToColor %}
{# Returns: {r: 52, g: 152, b: 219, h: 204, s: 70, l: 53} #}
```

#### `hex(array $color): string`
Converts color array to hex.

```twig
{% set primary = cms.toggle('brand-color') %}
<style>
    :root {
        --primary: {{ primary.color | hex }};
    }
</style>
```

#### `rgb(array $color, int $alpha = 100, bool $wrap = true): string`
Converts to RGB/RGBA format.

```twig
{% set primary = cms.color('primary') %}
background-color: {{ primary | rgb }};
background-color: {{ primary | rgb(50) }};  {# 50% opacity #}
background-color: {{ primary | rgb(75, false) }};  {# No wrapper #}
```

#### `hsl(array $color, int $alpha = 100, bool $wrap = true): string`
Converts to HSL/HSLA format.

```twig
{% set accent = cms.color('accent') | hsl %}
<div style="background: {{ accent }};">Content</div>

{# Semi-transparent overlay #}
<div style="background: {{ cms.color('accent') | hsl(20) }};">Overlay</div>
```

#### `oklch(array $color, int $alpha = 100, bool $wrap = true): string`
Converts to modern OKLCH format.

```twig
{# Modern color space for better gradients #}
background: {{ brand.primary | oklch }};
```

### Color Adjustment

#### `lightness(array $color, string $lightness): array`
Adjusts color lightness.

```twig
{% set base = "#3498db" | hexToColor %}
{% set light = base | lightness('+20') %}
{% set dark = base | lightness('-20') %}

<style>
    .button-primary { background: {{ base | hex }}; }
    .button-primary:hover { background: {{ dark | hex }}; }
    .button-primary:active { background: {{ light | hex }}; }
</style>
```

#### `chroma(array $color, string $chroma): array`
Adjusts color saturation/chroma.

```twig
{% set muted = brand.primary | chroma('-30') %}
<p style="color: {{ muted | hex }};">Muted text</p>
```

#### `hue(array $color, string $hue): array`
Adjusts color hue.

```twig
{% set complementary = brand.primary | hue('+180') %}
<div class="accent" style="border-color: {{ complementary | hex }};"></div>
```

#### `adjustColor(array $color, ?string $lightness = null, ?string $chroma = null, ?string $hue = null): array`
Adjusts multiple color properties at once.

```twig
{% set variant = brand.primary | adjustColor('+10', '-20', '+30') %}
```

## Array Filters

#### `count(array $array): int`
Counts array elements.

```twig
{% set posts = cms.objects('blog') %}
<p>{{ posts | count }} blog posts available</p>

{# Conditional display #}
{% if gallery.images | count > 0 %}
    <div class="gallery">...</div>
{% endif %}
```

#### `ksort(array $array): array`
Sorts array by keys.

```twig
{% set categories = cms.objects('categories') | ksort %}
{% for category in categories %}
    <option value="{{ category.id }}">{{ category.name }}</option>
{% endfor %}
```

#### `krsort(array $array): array`
Sorts array by keys in reverse order.

```twig
{% set years = posts | groupBy('year') | krsort %}
{% for year, yearPosts in years %}
    <h3>{{ year }}</h3>
    {# Posts for this year #}
{% endfor %}
```

#### `shuffle(array $array): array`
Randomly shuffles array elements.

```twig
{% set testimonials = cms.objects('testimonials') | shuffle | slice(0, 3) %}
{% for testimonial in testimonials %}
    <blockquote>{{ testimonial.quote }}</blockquote>
{% endfor %}
```

## Developer Filters

### Type Conversion

#### `typeof(mixed $variable): string`
Returns variable type.

```twig
{{ post.date | typeof }}  {# string #}
{{ gallery.images | typeof }}  {# array #}

{# Debug templates #}
{% if app.debug %}
    <pre>{{ dump(variable) }} ({{ variable | typeof }})</pre>
{% endif %}
```

#### `string(mixed $variable): string`
Converts to string.

```twig
{{ post.id | string }}
{{ user.age | string }}
```

#### `int(mixed $variable): int`
Converts to integer.

```twig
{% set page = get.page | int | default(1) %}
{% set limit = get.limit | int | default(10) %}
```

#### `float(mixed $variable): float`
Converts to float.

```twig
{% set price = product.price | float %}
<span class="price">${{ price | number_format(2) }}</span>
```

#### `bool(mixed $variable): bool`
Converts to boolean.

```twig
{% set featured = post.featured | bool %}
{% if featured %}
    <span class="badge">Featured</span>
{% endif %}
```

#### `array(mixed $variable): array`
Converts to array.

```twig
{% set tags = post.tags | array %}
{% for tag in tags %}
    <span class="tag">{{ tag }}</span>
{% endfor %}
```

### Debugging

#### `json_decode(mixed $variable): array`
Decodes JSON string to array.

```twig
{% set config = post.metadata | json_decode %}
{% for key, value in config %}
    <meta name="{{ key }}" content="{{ value }}">
{% endfor %}
```

#### `print_r(mixed $variable): string`
Pretty-prints variable for debugging.

```twig
{% if app.debug %}
    <pre>{{ post | print_r }}</pre>
{% endif %}
```

#### `var_dump(mixed $variable): string`
Detailed variable dump for debugging.

```twig
{% if app.debug %}
    <pre>{{ complex_object | var_dump }}</pre>
{% endif %}
```

## Collection Filtering and Sorting

### Advanced Collection Filtering

```twig
{# Filter blog posts by image size and status #}
{% set posts = cms.objects('blog') | filterCollection([
    {
        property: "image.size",
        operator: "gt",
        value: 1000000  {# Greater than 1MB #}
    },
    {
        property: "status",
        operator: "eq",
        value: "published"
    }
]) %}

{# Filter by date range #}
{% set recentPosts = cms.objects('blog') | filterCollection([
    {
        property: "date",
        operator: "gte",
        value: "now -30 days" | date('Y-m-d')
    }
]) %}

{# Filter with user input #}
{% set filteredProducts = cms.objects('products') | filterCollection([
    {
        property: "price",
        operator: "between",
        value: [get.min_price | default(0), get.max_price | default(1000)]
    },
    {
        property: "category",
        operator: "in",
        value: get.categories | default([])
    }
]) %}
```

### Collection Sorting

```twig
{# Sort by multiple criteria #}
{% set sortedPosts = cms.objects('blog') | sortCollection([
    {
        property: "featured",
        reverse: true  {# Featured first #}
    },
    {
        property: "date",
        reverse: true  {# Then by date descending #}
    },
    {
        property: "title",
        natural: true  {# Natural string sorting #}
    }
]) %}

{# Random shuffle #}
{% set randomProducts = cms.objects('products') | sortCollection([
    {
        property: "title",
        shuffle: true
    }
]) %}

{# Sort by custom property #}
{% set sortedGallery = cms.objects('gallery') | sortCollection([
    {
        property: "order",
        reverse: false
    }
]) %}
```

## Real-World Examples

### Blog Post Listing with Filters

```twig
{% set posts = cms.objects('blog')
    | filterCollection([
        {property: "status", operator: "eq", value: "published"},
        {property: "date", operator: "lte", value: "now" | date('Y-m-d')}
    ])
    | sortCollection([
        {property: "featured", reverse: true},
        {property: "date", reverse: true}
    ]) %}

<div class="blog-posts">
    {% for post in posts %}
        <article class="post {{ post.featured | bool ? 'featured' : '' }}">
            <h2>{{ post.title }}</h2>
            <div class="meta">
                <time>{{ post.date | date('F j, Y') }}</time>
                <span>{{ post.content | readtime }} min read</span>
                <span>{{ post.content | wordcount }} words</span>
            </div>
            <p>{{ post.excerpt | truncate(150, true) }}</p>

            {% if post.tags | count > 0 %}
                <div class="tags">
                    {% for tag in post.tags %}
                        <span class="tag">{{ tag | titleize }}</span>
                    {% endfor %}
                </div>
            {% endif %}
        </article>
    {% endfor %}
</div>
```

### Dynamic Color Theme

```twig
{% set theme = cms.objects('theme', 'colors') %}
{% set primary = theme.primary | hexToColor %}

<style>
    :root {
        --primary: {{ primary | hex }};
        --primary-light: {{ primary | lightness('+20') | hex }};
        --primary-dark: {{ primary | lightness('-20') | hex }};
        --primary-rgb: {{ primary | rgb(100, false) }};
        --primary-hsl: {{ primary | hsl(100, false) }};
    }

    .theme-preview {
        background: linear-gradient(
            45deg,
            {{ primary | hex }},
            {{ primary | hue('+60') | hex }}
        );
    }
</style>
```

Remember: These filters make Twig templates more powerful and help you process data without complex PHP logic in your templates!