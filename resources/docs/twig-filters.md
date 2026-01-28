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

#### `trim(string $string): string`
Removes whitespace from both sides.

```twig
{{ "  Hello World  " | trim }}
{# Output: "Hello World" #}
```

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

### Case Conversion

#### `ucwords(string $string): string`
Capitalizes the first letter of each word.

```twig
{{ "hello world" | ucwords }}
{# Output: "Hello World" #}
```

#### `lcfirst(string $string): string`
Lowercases the first character of a string.

```twig
{{ "Hello World" | lcfirst }}
{# Output: "hello World" #}
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

#### `prefixSlug(string|array $value, string $prefix = '', string $separator = ' '): string`
Slugifies and optionally prefixes a string or array of strings. Useful for generating CSS classes, HTML attributes, or URL-safe identifiers from user content.

```twig
{# Basic array with prefix - great for tag CSS classes #}
{{ post.tags | prefixSlug('tag-') }}
{# Input: ['Web Design', 'UI/UX'] → Output: "tag-web-design tag-ui-ux" #}

{# Single string #}
{{ "Hello World" | prefixSlug('prefix-') }}
{# Output: "prefix-hello-world" #}

{# Custom separator #}
{{ post.tags | prefixSlug('tag-', ', ') }}
{# Input: ['php', 'javascript'] → Output: "tag-php, tag-javascript" #}

{# No prefix - just slugify and join #}
{{ post.tags | prefixSlug }}
{# Input: ['Web Design', 'UI/UX'] → Output: "web-design ui-ux" #}

{# Empty items are automatically filtered out #}
{{ ['foo', '', 'bar'] | prefixSlug('x-') }}
{# Output: "x-foo x-bar" #}
```

**Parameters:**
- **`value`** - String or array of strings to process
- **`prefix`** - String to prepend to each slugified item (default: `''`)
- **`separator`** - String used to join items (default: `' '` space)

**Real-World Examples:**

```twig
{# Generate CSS classes for tags #}
<article class="{{ post.tags | prefixSlug('tag-') }}">
    {{ post.content }}
</article>

{# Create data attributes #}
<div data-categories="{{ post.categories | prefixSlug('', ',') }}">

{# Filter controls #}
{% for tag in allTags %}
    <button class="filter-btn" data-filter=".tag-{{ tag | prefixSlug }}">
        {{ tag }}
    </button>
{% endfor %}

{# Body class based on page sections #}
<body class="page-{{ page.id | prefixSlug }} {{ page.sections | prefixSlug('section-') }}">
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

### Markdown Filters

#### `markdown(mixed $value): string`
Processes full markdown syntax including block elements (paragraphs, headers, lists, etc.).

```twig
{% set content = "This is **bold** and [a link](https://example.com)" %}
{{ content | markdown }}
{# Output: <p>This is <strong>bold</strong> and <a href="https://example.com">a link</a></p> #}

{# Process blog post content #}
<article>
    {{ post.content | markdown }}
</article>

{# Markdown with tables and code blocks #}
{{ documentation.content | markdown }}
```

**Features:**
- Full markdown syntax support (headers, lists, blockquotes, etc.)
- GitHub Flavored Markdown (tables, fenced code blocks)
- Extended features (footnotes, definition lists)
- Wraps content in appropriate block elements (`<p>`, `<h1>`, etc.)

#### `markdownInline(mixed $value): string`
Processes only inline markdown syntax without wrapping content in `<p>` tags or other block elements. Perfect for processing markdown within existing HTML elements.

```twig
{% set text = "This is **bold** and [a link](https://example.com)" %}
{{ text | markdownInline }}
{# Output: This is <strong>bold</strong> and <a href="https://example.com">a link</a> #}

{# Use in headings #}
<h2>{{ page.title | markdownInline }}</h2>

{# Use in existing paragraphs #}
<p class="description">{{ product.tagline | markdownInline }}</p>

{# Use in list items #}
<ul>
    {% for feature in product.features %}
        <li>{{ feature | markdownInline }}</li>
    {% endfor %}
</ul>
```

**Supported Inline Syntax:**
- **Bold**: `**text**` → `<strong>text</strong>`
- **Italic**: `*text*` → `<em>text</em>`
- **Code**: `` `code` `` → `<code>code</code>`
- **Links**: `[text](url)` → `<a href="url">text</a>`
- **Auto-links**: `<https://example.com>` → `<a href="...">...</a>`
- **Images**: `![alt](url)` → `<img src="url" alt="alt" />`
- **Combined styles**: `**bold** with *italic*` works perfectly

**When to Use:**
- Processing markdown within existing HTML tags
- Headings, button labels, or navigation items with markdown
- Form field labels or help text with formatting
- List items or table cells with inline formatting
- Meta descriptions or short text snippets

**Comparison:**

```twig
{# markdown filter - adds <p> wrapper #}
{{ "Visit our **website**" | markdown }}
{# Output: <p>Visit our <strong>website</strong></p> #}

{# markdownInline filter - no wrapper #}
{{ "Visit our **website**" | markdownInline }}
{# Output: Visit our <strong>website</strong> #}
```

**Real-World Examples:**

```twig
{# Product features with inline formatting #}
<ul class="features">
    {% for feature in product.features %}
        <li>{{ feature | markdownInline }}</li>
        {# Input: "**High-speed** processing with *low power*" #}
        {# Output: <strong>High-speed</strong> processing with <em>low power</em> #}
    {% endfor %}
</ul>

{# Page titles with emphasis #}
<h1>{{ page.title | markdownInline }}</h1>
{# Input: "Welcome to our **Amazing** Store!" #}
{# Output: Welcome to our <strong>Amazing</strong> Store! #}

{# Navigation with inline links #}
<nav>
    {% for item in menu %}
        <a href="{{ item.url }}">{{ item.label | markdownInline }}</a>
    {% endfor %}
</nav>

{# Meta descriptions #}
<meta name="description" content="{{ page.meta | markdownInline | striptags }}">

{# Testimonial quotes with formatting #}
<blockquote>
    {{ testimonial.quote | markdownInline }}
    <cite>{{ testimonial.author }}</cite>
</blockquote>
```

**Security Note:** Both markdown filters run in safe mode with XSS protection enabled.

### Email and HTML Encoding

#### `htmlencode(string $text): string`
Encodes text to HTML entities, useful for obfuscating email addresses from scrapers.

```twig
{{ "user@example.com" | htmlencode }}
{# Output: &#117;&#115;&#101;&#114;&#64;&#101;&#120;&#97;&#109;&#112;&#108;&#101;&#46;&#99;&#111;&#109; #}

{# Useful for displaying emails without making them easily scrapable #}
<span>Contact: {{ contact.email | htmlencode }}</span>
```

#### `htmldecode(string $text): string`
Decodes HTML entities back to their original characters. Alias for `html_entity_decode`.

```twig
{{ "&amp;copy; 2024" | htmldecode }}
{# Output: © 2024 #}

{{ "&lt;div&gt;Hello&lt;/div&gt;" | htmldecode }}
{# Output: <div>Hello</div> #}

{# Useful when processing content from external sources #}
{{ externalContent.title | htmldecode }}
```

### URL Encoding

#### `urlencode(string $string): string`
URL-encodes a string.

```twig
{{ "hello world" | urlencode }}
{# Output: hello+world #}

<a href="/search?q={{ searchTerm | urlencode }}">Search</a>
```

#### `urldecode(string $string): string`
Decodes a URL-encoded string.

```twig
{{ "hello+world" | urldecode }}
{# Output: hello world #}
```

#### `rawurlencode(string $string): string`
URL-encodes a string according to RFC 3986 (spaces become %20).

```twig
{{ "hello world" | rawurlencode }}
{# Output: hello%20world #}
```

### Security Filters

#### `obfuscate(string $string): string`
Obfuscates a string for basic protection. Reversible with `deobfuscate`.

```twig
{{ "secret-data" | obfuscate }}
```

#### `deobfuscate(string $string): string`
Reverses obfuscation applied by `obfuscate`.

```twig
{{ obfuscatedString | deobfuscate }}
```

#### `encrypt(string $string): string`
Encrypts a string using the application's encryption key.

```twig
{{ sensitiveData | encrypt }}
```

#### `decrypt(string $string): string`
Decrypts a string that was encrypted with `encrypt`.

```twig
{{ encryptedData | decrypt }}
```

#### `mailto(string $email, string $subject = '', string $body = '', string $title = ''): string`
Creates an obfuscated mailto link that protects email addresses from spam bots. The email is not visible in the raw HTML source and is decoded via JavaScript.

```twig
{# Basic usage #}
{{ "info@example.com" | mailto }}

{# With subject #}
{{ "support@example.com" | mailto("Support Request") }}

{# With subject and body #}
{{ "sales@example.com" | mailto("Product Inquiry", "I'm interested in your products...") }}

{# With custom link title #}
{{ "contact@example.com" | mailto("", "", "Contact Our Team") }}

{# Real-world examples #}
<div class="contact-info">
    <p>General inquiries: {{ site.email | mailto }}</p>
    <p>Support: {{ "support@example.com" | mailto("Help needed") }}</p>
    <p>{{ "hr@example.com" | mailto("Job Application", "Position: ", "Apply Now") }}</p>
</div>
```

**How it works:**
- Email addresses are split and base64 encoded in data attributes
- Displayed using HTML entities (e.g., `&#64;` for @)
- JavaScript converts to real mailto links on page load
- If JavaScript is disabled, the encoded email is still visible

**Generated HTML:**
```html
<span class="mailto-obfuscated" 
      data-user="aW5mbw==" 
      data-domain="ZXhhbXBsZS5jb20=" 
      title="Email" 
      style="cursor:pointer;text-decoration:underline;">
    &#105;&#110;&#102;&#111;&#64;&#101;&#120;&#97;&#109;&#112;&#108;&#101;&#46;&#99;&#111;&#109;
</span>
```

**Note:** Requires `content.js` to be loaded on your pages for the JavaScript decoder to work.

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

#### `color(array $color, int $alpha = 100, bool $wrap = true): string`
Alias for `oklch`. Converts to OKLCH format.

```twig
background: {{ brand.primary | color }};
background: {{ brand.primary | color(50) }}; {# 50% opacity #}
```

#### `colour(array $color, int $alpha = 100, bool $wrap = true): string`
British spelling alias for `oklch`.

```twig
background: {{ brand.primary | colour }};
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

#### `sortBy(array $array, string $key): array`
Sorts an array of objects/arrays by a specific key.

```twig
{% set products = cms.objects('products') | sortBy('price') %}
{% for product in products %}
    <div>{{ product.name }} - ${{ product.price }}</div>
{% endfor %}

{# Sort users alphabetically by name #}
{% set users = cms.objects('users') | sortBy('name') %}
```

#### `shuffle(array $array): array`
Randomly shuffles array elements.

```twig
{% set testimonials = cms.objects('testimonials') | shuffle | slice(0, 3) %}
{% for testimonial in testimonials %}
    <blockquote>{{ testimonial.quote }}</blockquote>
{% endfor %}
```

#### `paginate(array $array, int $limit, int $page = 1): array`
Returns a paginated slice of an array.

```twig
{% set page = get.page | default(1) | int %}
{% set perPage = 10 %}
{% set posts = cms.objects('blog') | paginate(perPage, page) %}

{% for post in posts %}
    <article>{{ post.title }}</article>
{% endfor %}

{# Pagination with navigation #}
{% set allPosts = cms.objects('blog') %}
{% set totalPages = (allPosts | length / perPage) | round(0, 'ceil') %}
<nav>
    {% for p in 1..totalPages %}
        <a href="?page={{ p }}" {{ p == page ? 'class="active"' : '' }}>{{ p }}</a>
    {% endfor %}
</nav>
```

#### `unique(array $array): array`
Removes duplicate values from an array.

```twig
{% set tags = ["php", "javascript", "php", "css", "javascript"] %}
{{ tags | unique | join(', ') }}
{# Output: php, javascript, css #}

{# Get unique categories from blog posts #}
{% set allCategories = [] %}
{% for post in cms.objects('blog') %}
    {% set allCategories = allCategories | merge([post.category]) %}
{% endfor %}
{% set uniqueCategories = allCategories | unique %}

{# Build category filter #}
<select name="category">
    <option value="">All Categories</option>
    {% for category in uniqueCategories %}
        <option value="{{ category }}">{{ category | titleize }}</option>
    {% endfor %}
</select>

{# Unique tags across all posts #}
{% set allTags = [] %}
{% for post in cms.objects('blog') %}
    {% set allTags = allTags | merge(post.tags) %}
{% endfor %}
{% set uniqueTags = allTags | unique | sort %}

<div class="tag-cloud">
    {% for tag in uniqueTags %}
        <a href="/blog?tag={{ tag | urlencode }}">{{ tag }}</a>
    {% endfor %}
</div>

{# Remove duplicate authors #}
{% set authors = posts | map(p => p.author) | unique %}
<p>Authors: {{ authors | join(', ') }}</p>
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

## Date and Time Filters

Total CMS includes powerful date manipulation filters powered by CakePHP Chronos. These filters support natural language date strings like "tomorrow", "next monday", "+1 week", making date handling intuitive and flexible.

### Date Formatting

#### `dateFormat(mixed $date, string $format = 'Y-m-d H:i:s'): string`
Formats dates with custom format strings.

```twig
{{ post.created_at | dateFormat }}
{# Output: 2024-06-15 14:30:00 #}

{{ post.created_at | dateFormat('F j, Y') }}
{# Output: June 15, 2024 #}

{{ post.created_at | dateFormat('l, F d, Y g:i A') }}
{# Output: Saturday, June 15, 2024 2:30 PM #}

{# Works with smart date strings #}
{{ "tomorrow" | dateFormat('Y-m-d') }}
{{ "+1 week" | dateFormat('F j, Y') }}
```

#### `dateRelative(mixed $date): string`
Returns human-readable relative date strings.

```twig
{{ post.updated_at | dateRelative }}
{# Output: 2 days ago #}

{{ event.date | dateRelative }}
{# Output: in 3 weeks #}

{# Perfect for social media style timestamps #}
{% for comment in post.comments %}
    <time>{{ comment.created | dateRelative }}</time>
{% endfor %}
```

### Date Manipulation

#### `dateAdd(mixed $date, string $interval): string`
Adds time intervals to dates.

```twig
{{ "now" | dateAdd('+1 day') | dateFormat('Y-m-d') }}
{{ event.date | dateAdd('+2 weeks') | dateFormat('F j, Y') }}
{{ deadline | dateAdd('+3 hours') | dateFormat('g:i A') }}

{# Chain multiple additions #}
{{ "now" | dateAdd('+1 month') | dateAdd('+5 days') | dateFormat('Y-m-d') }}

{# Calculate future dates #}
{% set nextPayment = subscription.date | dateAdd('+1 month') %}
```

#### `dateSubtract(mixed $date, string $interval): string`
Subtracts time intervals from dates.

```twig
{{ "now" | dateSubtract('1 day') | dateFormat('Y-m-d') }}
{{ event.date | dateSubtract('2 weeks') | dateFormat('F j, Y') }}

{# Calculate past dates #}
{% set lastWeek = "now" | dateSubtract('7 days') %}
{% set lastMonth = "now" | dateSubtract('1 month') %}
```

#### `dateDiff(mixed $date1, mixed $date2): string`
Returns human-readable difference between two dates.

```twig
{{ start_date | dateDiff(end_date) }}
{# Output: 2 weeks before #}

{{ "now" | dateDiff(deadline) }}
{# Output: in 3 days #}

{# Project duration #}
<p>Project duration: {{ project.start | dateDiff(project.end) }}</p>
```

### Date Period Operations

#### `dateStartOf(mixed $date, string $unit = 'day'): string`
Returns the start of a period (day, week, month, year).

```twig
{{ "now" | dateStartOf('day') | dateFormat('Y-m-d H:i:s') }}
{# Output: 2024-06-15 00:00:00 #}

{{ "now" | dateStartOf('month') | dateFormat('Y-m-d') }}
{# Output: 2024-06-01 #}

{{ "now" | dateStartOf('year') | dateFormat('Y-m-d') }}
{# Output: 2024-01-01 #}

{# Get this week's start #}
{% set weekStart = "now" | dateStartOf('week') %}
```

#### `dateEndOf(mixed $date, string $unit = 'day'): string`
Returns the end of a period (day, week, month, year).

```twig
{{ "now" | dateEndOf('day') | dateFormat('Y-m-d H:i:s') }}
{# Output: 2024-06-15 23:59:59 #}

{{ "now" | dateEndOf('month') | dateFormat('Y-m-d') }}
{# Output: 2024-06-30 #}

{# Get month range #}
{% set monthStart = "now" | dateStartOf('month') %}
{% set monthEnd = "now" | dateEndOf('month') %}
```

### Date Validation

#### `dateIsWeekend(mixed $date): bool`
Checks if a date falls on a weekend.

```twig
{% if event.date | dateIsWeekend %}
    <span class="badge">Weekend Event</span>
{% endif %}

{# Business hours check #}
{% if "now" | dateIsWeekend %}
    <p>Our office is closed on weekends</p>
{% endif %}
```

#### `dateIsWeekday(mixed $date): bool`
Checks if a date is a weekday (Monday-Friday).

```twig
{% if meeting.date | dateIsWeekday %}
    <p>Regular business hours apply</p>
{% endif %}
```

#### `dateIsPast(mixed $date): bool`
Checks if a date is in the past.

```twig
{% if event.date | dateIsPast %}
    <span class="badge">Past Event</span>
{% else %}
    <a href="/register">Register Now</a>
{% endif %}

{# Deadline checking #}
{% if task.deadline | dateIsPast %}
    <div class="alert alert-danger">Overdue!</div>
{% endif %}
```

#### `dateIsFuture(mixed $date): bool`
Checks if a date is in the future.

```twig
{% if product.launch_date | dateIsFuture %}
    <span class="badge">Coming Soon</span>
{% else %}
    <button>Buy Now</button>
{% endif %}
```

#### `dateIsToday(mixed $date): bool`
Checks if a date is today.

```twig
{% if event.date | dateIsToday %}
    <div class="alert alert-info">Event is TODAY!</div>
{% endif %}

{# Birthday check #}
{% if user.birthday | dateFormat('m-d') == "now" | dateFormat('m-d') %}
    <p>🎉 Happy Birthday!</p>
{% endif %}
```

### Recurring Date Operations

#### `recurringMonthDate(mixed $date, mixed $targetDate = null): string`
Gets the recurring date for a target month. Useful for subscription billing dates - automatically handles end-of-month clamping (e.g., Jan 31st becomes Feb 28th).

```twig
{# Get this month's billing date based on subscription start #}
{{ subscription.startDate | recurringMonthDate | date('M j, Y') }}

{# Get billing date for a specific month #}
{{ subscription.startDate | recurringMonthDate('2026-03-01') | date('M j, Y') }}

{# Calculate next payment date #}
{% set nextPayment = membership.startDate | recurringMonthDate %}
<p>Your next payment is on {{ nextPayment | date('F j, Y') }}</p>

{# Show payment schedule #}
{% set months = ['2026-01-01', '2026-02-01', '2026-03-01'] %}
<ul>
{% for month in months %}
    <li>{{ subscription.startDate | recurringMonthDate(month) | date('F j, Y') }}</li>
{% endfor %}
</ul>
```

**End-of-month clamping examples:**
- January 31st → February 28th (or 29th in leap year)
- January 31st → March 31st (no clamping needed)
- January 30th → February 28th (clamped)
- January 15th → February 15th (no clamping needed)

#### `dateIsRecurringDate(mixed $date, mixed $compareDate = null): bool`
Checks if a comparison date falls on the recurring day of the original date. Useful for determining if today is a billing day for a subscription.

```twig
{# Check if today is the billing day #}
{% if subscription.startDate | dateIsRecurringDate %}
    <div class="alert alert-warning">Payment due today!</div>
{% endif %}

{# Check against a specific date #}
{% if subscription.startDate | dateIsRecurringDate(checkDate) %}
    <p>This is a billing day for this subscription.</p>
{% endif %}

{# Billing reminder logic #}
{% set tomorrow = "now" | dateAdd('+1 day') %}
{% if subscription.startDate | dateIsRecurringDate(tomorrow) %}
    <div class="notice">Payment due tomorrow!</div>
{% endif %}

{# Process payments for today #}
{% for member in members %}
    {% if member.billingDate | dateIsRecurringDate %}
        <div class="payment-due">{{ member.name }} - Payment due today</div>
    {% endif %}
{% endfor %}
```

**Real-World Example - Subscription Management:**
```twig
{% set subscription = cms.object('subscriptions', user.id) %}

<div class="subscription-info">
    <h3>{{ subscription.plan }} Plan</h3>
    <p>Started: {{ subscription.startDate | date('F j, Y') }}</p>
    <p>Next billing: {{ subscription.startDate | recurringMonthDate | date('F j, Y') }}</p>

    {% if subscription.startDate | dateIsRecurringDate %}
        <div class="alert alert-info">
            Your payment is being processed today.
        </div>
    {% endif %}

    {# Show upcoming billing dates #}
    <h4>Upcoming Payments</h4>
    <ul>
        {% for i in 1..3 %}
            {% set futureMonth = "now" | dateAdd('+' ~ i ~ ' months') %}
            <li>{{ subscription.startDate | recurringMonthDate(futureMonth) | date('F j, Y') }}</li>
        {% endfor %}
    </ul>
</div>
```

### Smart Date Strings

The date filters support natural language strings powered by Chronos:

```twig
{# Relative dates #}
{{ "now" | dateFormat('Y-m-d H:i:s') }}
{{ "today" | dateFormat('Y-m-d') }}
{{ "tomorrow" | dateFormat('Y-m-d') }}
{{ "yesterday" | dateFormat('Y-m-d') }}

{# Relative intervals #}
{{ "+1 day" | dateFormat('Y-m-d') }}
{{ "-1 week" | dateFormat('Y-m-d') }}
{{ "+2 months" | dateFormat('Y-m-d') }}
{{ "-1 year" | dateFormat('Y-m-d') }}

{# Natural language #}
{{ "next monday" | dateFormat('l, F j') }}
{{ "last friday" | dateFormat('Y-m-d') }}
{{ "first day of this month" | dateFormat('Y-m-d') }}
{{ "last day of this month" | dateFormat('Y-m-d') }}
{{ "first day of next month" | dateFormat('Y-m-d') }}
```

### Real-World Date Examples

#### Event Management
```twig
{% set event = cms.object('events', 'summer-conference') %}

<div class="event-card">
    <h3>{{ event.title }}</h3>
    
    {# Display formatted date #}
    <p>📅 {{ event.date | dateFormat('l, F j, Y \\a\\t g:i A') }}</p>
    
    {# Show relative time #}
    <p>{{ event.date | dateRelative }}</p>
    
    {# Status badges #}
    {% if event.date | dateIsToday %}
        <span class="badge badge-primary">Today!</span>
    {% elseif event.date | dateIsPast %}
        <span class="badge badge-secondary">Past Event</span>
    {% elseif event.date | dateIsFuture %}
        <span class="badge badge-success">Upcoming</span>
    {% endif %}
    
    {# Registration deadline #}
    {% set deadline = event.date | dateSubtract('1 week') %}
    {% if deadline | dateIsFuture %}
        <p>Registration closes {{ deadline | dateRelative }}</p>
    {% else %}
        <p>Registration closed</p>
    {% endif %}
</div>
```

#### Blog Post Scheduling
```twig
{% for post in cms.objects('blog') %}
    <article>
        <h2>{{ post.title }}</h2>
        
        {# Publication status #}
        {% if post.publish_date | dateIsFuture %}
            <div class="scheduled">
                ⏰ Scheduled for {{ post.publish_date | dateFormat('F j, Y') }}
                ({{ post.publish_date | dateRelative }})
            </div>
        {% else %}
            <time>Published {{ post.publish_date | dateRelative }}</time>
        {% endif %}
        
        {# Show content only if published #}
        {% if post.publish_date | dateIsPast or post.publish_date | dateIsToday %}
            <p>{{ post.excerpt }}</p>
        {% endif %}
    </article>
{% endfor %}
```

#### Task Management
```twig
{% set tasks = cms.objects('tasks') %}

{% for task in tasks %}
    <div class="task {% if task.due_date | dateIsPast %}overdue{% endif %}">
        <h4>{{ task.title }}</h4>
        
        {# Due date with smart formatting #}
        {% if task.due_date | dateIsToday %}
            <span class="due-today">Due today!</span>
        {% elseif task.due_date | dateFormat('Y-m-d') == "now" | dateAdd('+1 day') | dateFormat('Y-m-d') %}
            <span class="due-tomorrow">Due tomorrow</span>
        {% else %}
            <span>Due {{ task.due_date | dateRelative }}</span>
        {% endif %}
        
        {# Days remaining/overdue #}
        {% set daysUntil = "now" | dateDiff(task.due_date) %}
        <small>{{ daysUntil }}</small>
    </div>
{% endfor %}
```

#### Business Hours
```twig
{% set now = "now" %}
{% set openTime = now | dateStartOf('day') | dateAdd('+9 hours') %}
{% set closeTime = now | dateStartOf('day') | dateAdd('+17 hours') %}

<div class="business-hours">
    <h3>Store Hours</h3>
    
    {% if now | dateIsWeekend %}
        <p class="closed">🚫 Closed on weekends</p>
    {% elseif now >= openTime and now <= closeTime %}
        <p class="open">✅ Open until {{ closeTime | dateFormat('g:i A') }}</p>
    {% elseif now < openTime %}
        <p class="closed">🚫 Opens at {{ openTime | dateFormat('g:i A') }}</p>
    {% else %}
        <p class="closed">🚫 Closed - Opens tomorrow at 9:00 AM</p>
    {% endif %}
</div>
```

## Collection Filtering and Sorting

> **📚 Full Documentation:** For a complete reference of all 40+ filtering operators, including detailed examples and best practices, see [Collection Filtering and Sorting Guide](collection-filtering.md).

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

### Array Rule Logic: OR vs AND

When filtering with an array of values, you can control whether items must match **ANY** value (OR logic) or **ALL** values (AND logic) using the `logic` parameter.

#### OR Logic (Default)
Returns items that match **ANY** of the values in the array:

```twig
{# Posts tagged with 'php' OR 'javascript' OR 'web' #}
{% set posts = cms.objects('blog') | filterCollection([
    {
        property: "tags",
        operator: "contains",
        value: ["php", "javascript", "web"],
        logic: "or"  {# Default - can be omitted #}
    }
]) %}

{# Products in category 'electronics' OR 'computers' #}
{% set products = cms.objects('products') | filterCollection([
    {
        property: "category",
        operator: "equal",
        value: ["electronics", "computers"]
        {# logic: "or" is the default #}
    }
]) %}
```

#### AND Logic
Returns items that match **ALL** of the values in the array:

```twig
{# Posts that contain ALL tags: 'php' AND 'framework' AND 'tutorial' #}
{% set advancedPosts = cms.objects('blog') | filterCollection([
    {
        property: "tags",
        operator: "contains",
        value: ["php", "framework", "tutorial"],
        logic: "and"
    }
]) %}

{# Events on weekends AND evening time #}
{% set weekendEvenings = cms.objects('events') | filterCollection([
    {
        property: "day_of_week",
        operator: "equal",
        value: ["saturday", "sunday"],
        logic: "and"  {# Must be both saturday AND sunday - unlikely but demonstrates concept #}
    }
]) %}

{# Products with ALL specified features #}
{% set premiumProducts = cms.objects('products') | filterCollection([
    {
        property: "features",
        operator: "contains",
        value: ["waterproof", "wireless", "fast-charging"],
        logic: "and"  {# Must have ALL three features #}
    }
]) %}
```

#### Real-World AND Logic Examples

**Multi-tag content filtering:**
```twig
{# Find blog posts that cover ALL specified topics #}
{% set comprehensivePosts = cms.objects('blog') | filterCollection([
    {
        property: "tags",
        operator: "contains", 
        value: ["security", "performance", "scalability"],
        logic: "and"
    }
]) %}

<h2>Comprehensive Guides</h2>
<p>Posts covering security, performance, and scalability:</p>
{% for post in comprehensivePosts %}
    <article>{{ post.title }}</article>
{% endfor %}
```

**Product feature requirements:**
```twig
{# Products that have ALL required features #}
{% set requiredFeatures = ["bluetooth", "waterproof", "long-battery"] %}
{% set suitableProducts = cms.objects('products') | filterCollection([
    {
        property: "features",
        operator: "contains",
        value: requiredFeatures,
        logic: "and"
    }
]) %}

<h2>Products with ALL Required Features</h2>
{% if suitableProducts | length > 0 %}
    {% for product in suitableProducts %}
        <div class="product">{{ product.name }}</div>
    {% endfor %}
{% else %}
    <p>No products match all requirements</p>
{% endif %}
```

**Event availability filtering:**
```twig
{# Events available on ALL specified days #}
{% set availableDays = ["monday", "wednesday", "friday"] %}
{% set consistentEvents = cms.objects('events') | filterCollection([
    {
        property: "available_days",
        operator: "contains",
        value: availableDays,
        logic: "and"
    }
]) %}
```

#### Logic Parameter Summary

| Logic | Behavior | Use Case |
|-------|----------|----------|
| `"or"` (default) | Match **ANY** value | Broad filtering, multiple categories |
| `"and"` | Match **ALL** values | Strict requirements, feature completeness |

**Performance Note:** AND logic applies filters sequentially, making it more restrictive and potentially faster for large collections since it narrows results with each filter.

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

## File Size Formatting

### `filesize(mixed $bytes, int $decimals = 1): string`
Formats byte values into human-readable file sizes.

```twig
{# Basic usage #}
{{ 500 | filesize }}
{# Output: 500 B #}

{{ 1024 | filesize }}
{# Output: 1 KB #}

{{ 1536 | filesize }}
{# Output: 2 KB #}

{{ 1048576 | filesize }}
{# Output: 1.0 MB #}

{{ 1572864 | filesize }}
{# Output: 1.5 MB #}

{{ 1073741824 | filesize }}
{# Output: 1.0 GB #}

{# Custom decimal places (only applies to MB and larger) #}
{{ 1572864 | filesize(2) }}
{# Output: 1.50 MB #}

{{ 1572864 | filesize(0) }}
{# Output: 2 MB #}
```

#### Behavior:
- **Bytes (B)** and **Kilobytes (KB)**: Always shown as whole numbers (no decimals)
- **Megabytes (MB)** and larger: Shown with decimals (default: 1 decimal place)
- Supports units: B, KB, MB, GB, TB, PB
- Returns "0 B" for invalid or negative input

#### Real-World Examples:

```twig
{# Display image file size #}
{% set image = cms.data('image', 'hero-image', 'image') %}
<p>File size: {{ image.size | filesize }}</p>

{# File upload listing #}
{% for file in cms.objects('depot', 'downloads').files %}
    <div class="file">
        <span class="name">{{ file.name }}</span>
        <span class="size">{{ file.size | filesize }}</span>
    </div>
{% endfor %}

{# Gallery with file info #}
{% set gallery = cms.gallery('portfolio') %}
{% for item in gallery %}
    <figure>
        <img src="{{ cms.galleryPath(gallery, item.name) }}" alt="{{ item.alt }}">
        <figcaption>
            {{ item.name }} ({{ item.size | filesize }}, {{ item.width }}x{{ item.height }})
        </figcaption>
    </figure>
{% endfor %}

{# Storage usage display #}
{% set totalSize = files | map(f => f.size) | sum %}
<div class="storage-info">
    <p>Total storage used: {{ totalSize | filesize }}</p>
</div>
```

## Price Formatting

### `price(mixed $price, string $currency = '$', string $format = 'prepend'): string`
Formats price values with currency symbols.

```twig
{# Basic usage #}
{{ 19.99 | price }}
{# Output: $19.99 #}

{{ 19.99 | price('€') }}
{# Output: €19.99 #}

{# Different formats #}
{{ 19.99 | price('USD', 'prepend') }}
{# Output: USD19.99 #}

{{ 19.99 | price('USD', 'append') }}
{# Output: 19.99 USD #}

{{ 19.99 | price('', 'none') }}
{# Output: 19.99 #}
```

#### Format Options:
- **`prepend`** (default): Places currency before the number (`$19.99`)
- **`append`**: Places currency after the number with a space (`19.99 USD`)
- **`none`**: Shows only the formatted number (`19.99`)

#### Real-World Examples:

```twig
{# Product listing #}
{% for product in cms.objects('products') %}
    <div class="product">
        <h3>{{ product.name }}</h3>
        <span class="price">{{ product.price | price }}</span>
        
        {# Sale price with comparison #}
        {% if product.sale_price %}
            <span class="sale">{{ product.sale_price | price }}</span>
            <span class="original">{{ product.price | price }}</span>
        {% endif %}
    </div>
{% endfor %}

{# International pricing #}
{% set currency = get.currency | default('USD') %}
{% for product in products %}
    <span class="price">
        {{ product.price | price(currency, 'append') }}
    </span>
{% endfor %}

{# Price ranges #}
{% set minPrice = products | map(p => p.price) | min %}
{% set maxPrice = products | map(p => p.price) | max %}
<p>Prices from {{ minPrice | price }} to {{ maxPrice | price }}</p>

{# Shopping cart totals #}
{% set subtotal = cart.items | map(item => item.price * item.quantity) | sum %}
{% set tax = subtotal * 0.08 %}
{% set total = subtotal + tax %}

<div class="cart-totals">
    <div>Subtotal: {{ subtotal | price }}</div>
    <div>Tax: {{ tax | price }}</div>
    <div class="total">Total: {{ total | price }}</div>
</div>
```

Remember: These filters make Twig templates more powerful and help you process data without complex PHP logic in your templates!