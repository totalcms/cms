# Twig Markdown Integration

Total CMS provides seamless markdown processing through Twig templates using ParsedownExtra, a powerful PHP markdown parser that supports extended markdown syntax.

## Overview

The markdown integration allows you to:
- Process markdown content stored in CMS collections
- Use extended markdown features like tables, footnotes, and fenced code blocks
- Safely render user-generated markdown content with built-in XSS protection
- Combine markdown with Twig templating for dynamic content

## Markdown Parser

Total CMS uses **ParsedownExtra** which extends the standard Parsedown library with additional features:

- **Standard Markdown**: Headers, paragraphs, lists, links, images, emphasis
- **GitHub Flavored Markdown**: Fenced code blocks, tables, strikethrough
- **Extended Features**: Footnotes, definition lists, abbreviations
- **Security**: Safe mode enabled to prevent XSS attacks
- **Line Breaks**: Automatic conversion of single line breaks to `<br>` tags

## Basic Usage

### The `|markdown` Filter

Use the `|markdown` filter to process full markdown content including block elements (paragraphs, headers, lists, etc.):

```twig
{# Process markdown content #}
{{ content|markdown }}

{# Process markdown from a collection object #}
{{ post.content|markdown }}

{# Combine with other filters #}
{{ article.body|markdown|raw }}
```

### The `|markdownInline` Filter

Use `|markdownInline` when you need to process markdown within existing HTML elements without adding wrapper tags like `<p>`:

```twig
{# In headings - no <p> wrapper added #}
<h2>{{ page.title|markdownInline }}</h2>

{# In list items #}
<ul>
    {% for feature in product.features %}
        <li>{{ feature|markdownInline }}</li>
    {% endfor %}
</ul>

{# In existing paragraphs #}
<p class="tagline">{{ product.tagline|markdownInline }}</p>
```

**Comparison:**

```twig
{# markdown filter - adds <p> wrapper #}
{{ "Visit our **website**"|markdown }}
{# Output: <p>Visit our <strong>website</strong></p> #}

{# markdownInline filter - no wrapper #}
{{ "Visit our **website**"|markdownInline }}
{# Output: Visit our <strong>website</strong> #}
```

**Supported inline syntax:** bold (`**text**`), italic (`*text*`), code (`` `code` ``), links (`[text](url)`), and images (`![alt](url)`).

### Processing Collection Content

When working with blog posts or articles stored in collections:

```twig
{# Display a blog post with markdown content #}
<article>
    <h1>{{ post.title }}</h1>
    <div class="meta">
        <time>{{ post.date|date('F j, Y') }}</time>
        <span>by {{ post.author }}</span>
    </div>
    <div class="content">
        {{ post.content|markdown }}
    </div>
</article>
```

### Processing String Properties

For StringData properties that contain markdown:

```twig
{# StringData with markdown content #}
<div class="description">
    {{ product.description|markdown }}
</div>

{# Check if content contains markdown before processing #}
{% if article.summary contains '#' or article.summary contains '*' %}
    {{ article.summary|markdown }}
{% else %}
    <p>{{ article.summary }}</p>
{% endif %}
```

## Advanced Markdown Features

### Tables

ParsedownExtra supports GitHub-style tables:

```markdown
| Feature | Status | Notes |
|---------|--------|-------|
| Headers | ✓ | H1-H6 supported |
| Tables | ✓ | GitHub style |
| Footnotes | ✓ | Extended syntax |
```

```twig
{# Process table content #}
<div class="table-responsive">
    {{ specs.comparison_table|markdown }}
</div>
```

### Code Blocks

Fenced code blocks with syntax highlighting:

```markdown
```php
<?php
echo "Hello World!";
```
```

```twig
{# Code documentation with syntax highlighting #}
<div class="code-documentation">
    {{ tutorial.code_examples|markdown }}
</div>
```

### Footnotes

Extended footnotes syntax:

```markdown
This is a text with footnote[^1].

[^1]: This is the footnote content.
```

```twig
{# Academic or detailed content with footnotes #}
<div class="research-paper">
    {{ paper.content|markdown }}
</div>
```

## Template Examples

### Blog Template

```twig
{# blog-post.twig #}
{% extends "layouts/main.twig" %}

{% block content %}
<div class="blog-post">
    <header class="post-header">
        <h1>{{ post.title }}</h1>
        <div class="post-meta">
            <time datetime="{{ post.date|date('c') }}">
                {{ post.date|date('F j, Y') }}
            </time>
            {% if post.author %}
                <span class="author">by {{ post.author }}</span>
            {% endif %}
            {% if post.tags %}
                <div class="tags">
                    {% for tag in post.tags %}
                        <span class="tag">{{ tag }}</span>
                    {% endfor %}
                </div>
            {% endif %}
        </div>
    </header>

    <div class="post-content">
        {{ post.content|markdown }}
    </div>

    {% if post.excerpt %}
        <div class="post-excerpt">
            <h3>Summary</h3>
            {{ post.excerpt|markdown }}
        </div>
    {% endif %}
</div>
{% endblock %}
```

### Documentation Template

```twig
{# documentation.twig #}
{% extends "layouts/docs.twig" %}

{% block content %}
<div class="documentation">
    {% if page.toc %}
        <aside class="table-of-contents">
            {{ page.toc|markdown }}
        </aside>
    {% endif %}

    <main class="doc-content">
        <h1>{{ page.title }}</h1>
        
        {% if page.description %}
            <div class="description">
                {{ page.description|markdown }}
            </div>
        {% endif %}

        <div class="content">
            {{ page.content|markdown }}
        </div>

        {% if page.code_examples %}
            <section class="examples">
                <h2>Examples</h2>
                {{ page.code_examples|markdown }}
            </section>
        {% endif %}
    </main>
</div>
{% endblock %}
```

### Product Listing with Markdown Descriptions

```twig
{# products.twig #}
<div class="products-grid">
    {% for product in cms.collection.objects('products') %}
        <div class="product-card">
            {% if product.image %}
                <img src="{{ product.image }}" alt="{{ product.title }}">
            {% endif %}
            
            <div class="product-info">
                <h3>{{ product.title }}</h3>
                
                {% if product.short_description %}
                    <div class="short-description">
                        {{ product.short_description|markdown }}
                    </div>
                {% endif %}
                
                <div class="price">${{ product.price }}</div>
                
                <a href="/product/{{ product.slug }}" class="btn">
                    View Details
                </a>
            </div>
        </div>
    {% endfor %}
</div>
```

## Security Considerations

ParsedownExtra runs in **safe mode** by default, which:

- Prevents execution of raw HTML that could contain malicious scripts
- Filters out dangerous HTML attributes and elements
- Protects against XSS (Cross-Site Scripting) attacks
- Maintains the security of user-generated content

```twig
{# Safe processing of user-generated markdown #}
<div class="user-content">
    {{ user_comment.content|markdown }}
    {# XSS protection is automatic #}
</div>
```

## Performance Tips

### Caching Processed Content

For frequently accessed content, consider caching the processed markdown:

```twig
{# Use Twig's cache for expensive operations #}
{% set processed_content %}
    {{ large_document.content|markdown }}
{% endset %}

{% cache 'doc_' ~ large_document.id for 3600 %}
    {{ processed_content }}
{% endcache %}
```

### Conditional Processing

Only process content through markdown when necessary:

```twig
{# Check if content needs markdown processing #}
{% if content contains '#' or content contains '*' or content contains '[' %}
    {{ content|markdown }}
{% else %}
    <p>{{ content|nl2br }}</p>
{% endif %}
```

## Combining with Other Features

### Markdown with Form Builder

```twig
{# Create forms that accept markdown input #}
{{ cms.form.textarea('content', {
    'label': 'Content',
    'help': 'You can use markdown syntax here',
    'rows': 10,
    'class': 'markdown-editor'
}) }}

{# Preview processed markdown #}
{% if content %}
    <div class="markdown-preview">
        <h4>Preview:</h4>
        {{ content|markdown }}
    </div>
{% endif %}
```

### Markdown in Email Templates

```twig
{# email-template.twig #}
<div class="email-content">
    <h1>{{ email.subject }}</h1>
    
    <div class="message">
        {{ email.message|markdown }}
    </div>
    
    {% if email.signature %}
        <div class="signature">
            {{ email.signature|markdown }}
        </div>
    {% endif %}
</div>
```

## Best Practices

1. **Content Structure**: Store markdown in StringData properties for automatic sanitization
2. **Performance**: Cache processed markdown for large documents
3. **User Experience**: Provide markdown syntax help for content editors
4. **Validation**: Validate markdown content before saving to collections
5. **Fallback**: Always provide fallback rendering for non-markdown content

## Common Use Cases

- **Blog Posts**: Article content with rich formatting
- **Documentation**: Technical documentation with code examples
- **Product Descriptions**: Rich product information with formatting
- **Comments**: User-generated content with safe formatting
- **Email Templates**: Rich email content with markdown formatting
- **Static Pages**: Content pages with complex formatting needs

The markdown integration in Total CMS provides a powerful yet secure way to handle rich text content while maintaining the flexibility of Twig templating.