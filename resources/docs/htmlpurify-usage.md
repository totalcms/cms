# HTMLPurify Configuration Guide

Total CMS includes built-in HTML sanitization to protect against XSS attacks while preserving legitimate HTML content for content creators.

## Quick Start

**HTML sanitization is ENABLED BY DEFAULT** for security. Content containing HTML tags will be automatically sanitized when stored.

### Examples

```php
// ✅ SAFE: Script tags and malicious attributes are removed
$content = '<p>Safe content</p><script>alert("XSS")</script>';
$data = new StringData($content);
echo $data->text; // Output: <p>Safe content</p>

// ✅ PRESERVED: Legitimate HTML formatting is kept
$content = '<p>Content with <strong>formatting</strong> and <a href="https://example.com">links</a></p>';
$data = new StringData($content);
echo $data->text; // Output: Same as input (safe HTML preserved)
```

## Configuration Options

### 1. Global Configuration (`config/defaults.php`)

```php
$settings['htmlpurify'] = [
    'allowed_tags' => 'p,br,strong,b,em,i,a[href|title],ul,ol,li',
    'allowed_css' => 'color,font-weight,text-align',
    'safe_iframe_domains' => ['www.youtube.com/embed/', 'player.vimeo.com/video/'],
    // ... other settings
];
```

### 2. Field-Level Configuration

```php
// Disable sanitization for a specific field
$settings = ['htmlpurify' => false];
$data = new StringData($content, $settings);

// Custom sanitization rules for a field
$settings = [
    'htmlpurify' => [
        'html_sanitizer' => [
            'allowed_tags' => 'p,br,strong',
            'allowed_css' => 'color'
        ]
    ]
];
$data = new StringData($content, $settings);
```

### 3. Disable Globally

To disable HTML sanitization entirely (NOT RECOMMENDED):

```php
// In config/defaults.php
$settings['htmlpurify'] = false;
```

## Configuration Reference

### Allowed Tags
Control which HTML elements are permitted:

```php
'allowed_tags' => 'p,br,strong,b,em,i,u,strike,del,a[href|title|target|rel],ul,ol,li,h1,h2,h3,h4,h5,h6'
```

### Allowed CSS Properties
Control which inline styles are permitted:

```php
'allowed_css' => 'color,background-color,font-size,font-weight,text-align,margin,padding'
```

### Safe Iframe Domains
Whitelist trusted domains for iframe embeds:

```php
'safe_iframe_domains' => [
    'www.youtube.com/embed/',
    'player.vimeo.com/video/',
    'codepen.io/embed/'
]
```

## Security Levels

### 1. Default (Recommended)
- Allows common HTML formatting
- Removes all script tags and event handlers
- Permits safe iframe embeds from trusted domains

### 2. Strict Mode
- Basic formatting only (bold, italic, links)
- No iframes or advanced HTML
- Suitable for user comments or untrusted content

### 3. Disabled (High Risk)
- No sanitization applied
- All HTML content preserved as-is
- **Only use for fully trusted content sources**

## Common Use Cases

### Blog Content
```php
'htmlpurify' => [
    'html_sanitizer' => [
        'allowed_tags' => 'p,br,strong,b,em,i,u,a[href|title],ul,ol,li,h1,h2,h3,h4,h5,h6,blockquote,img[src|alt|title]',
        'safe_iframe_domains' => ['www.youtube.com/embed/', 'player.vimeo.com/video/']
    ]
]
```

### Product Descriptions
```php
'htmlpurify' => [
    'html_sanitizer' => [
        'allowed_tags' => 'p,br,strong,b,em,i,ul,ol,li,table,tr,td,th',
        'allowed_css' => 'color,font-weight,text-align'
    ]
]
```

### User Comments (Strict)
```php
'htmlpurify' => [
    'html_sanitizer' => [
        'allowed_tags' => 'p,br,strong,b,em,i,a[href|title]',
        'allowed_css' => ''
    ]
]
```

### Trusted Admin Content (Minimal Sanitization)
```php
'htmlpurify' => [
    'html_sanitizer' => [
        'allowed_tags' => HTMLSanitizerConfig::DEFAULT_ALLOWED_TAGS,
        'allowed_css' => HTMLSanitizerConfig::DEFAULT_ALLOWED_CSS
    ]
]
```

## HTML5 Limitations

HTMLPurifier does not natively support modern HTML5 elements:

**NOT SUPPORTED:**
- `<video>`, `<audio>`, `<source>`, `<track>`
- `<picture>`, `<figure>`, `<figcaption>`
- `<article>`, `<section>`, `<header>`, `<footer>`
- `<details>`, `<summary>`, `<mark>`, `<time>`

**ALTERNATIVES:**
1. Use iframe embeds for video content (YouTube, Vimeo)
2. Store media files separately and reference via custom fields
3. Disable sanitization for specific media-rich fields (with caution)

## Best Practices

1. **Keep sanitization enabled** for user-generated content
2. **Use strict mode** for comments and untrusted sources
3. **Whitelist iframe domains** rather than allowing all embeds
4. **Test your configuration** with sample content before going live
5. **Disable only when necessary** and understand the security implications
6. **Use CSP headers** as an additional security layer

## Troubleshooting

### Content Being Stripped
If legitimate content is being removed:
1. Check your `allowed_tags` configuration
2. Verify CSS properties are in `allowed_css`
3. Ensure iframe domains are in `safe_iframe_domains`

### HTML5 Elements Removed
Modern HTML5 elements are not supported by HTMLPurifier. Consider:
1. Using supported alternatives
2. Implementing custom shortcodes for media
3. Selectively disabling sanitization for trusted content

### Performance Concerns
HTML sanitization adds processing overhead:
1. HTMLPurifier caches processed content
2. Sanitization only runs when HTML is detected
3. Configure cache path in settings for better performance