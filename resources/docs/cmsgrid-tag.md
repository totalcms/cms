# CMS Grid Tag Reference

The `{% cmsgrid %}` tag provides a powerful way to display collections of content in customizable grid layouts with built-in styling and helper methods.

## Syntax

```twig
{% cmsgrid objects from 'collection' with 'classes' as 'tag' %}
    {# Template content with {{ item }} and {{ collection }} variables #}
{% endcmsgrid %}
```

### Parameters

- **`objects`** (required) - Array of objects to display
- **`from 'collection'`** (optional) - Collection name, available as `{{ collection }}` variable
- **`with 'classes'`** (optional) - CSS classes for the grid container
- **`as 'tag'`** (optional) - HTML tag for grid items (default: `div`)

## Basic Examples

### Simple Blog Grid
```twig
{% cmsgrid cms.objects('blog') from 'blog' with 'blog compact' %}
    <h3>{{ item.title }}</h3>
    <p>{{ item.summary }}</p>
    <time>{{ cms.grid.date(item.date) }}</time>
{% endcmsgrid %}
```

### Using Built-in Templates
```twig
{% cmsgrid cms.objects('blog') from 'blog' with 'blog list' %}
    {% include 'grid/blog.twig' %}
{% endcmsgrid %}
```

### Custom Item Tags
```twig
{% cmsgrid cms.objects('products') from 'products' with 'products grid' as 'article' %}
    <h4>{{ item.name }}</h4>
    <span class="price">{{ item.price|price }}</span>
{% endcmsgrid %}
```

## Available Variables

### `{{ item }}`
Each object in the collection array with all its properties:
```twig
{{ item.title }}
{{ item.summary }}
{{ item.date }}
{{ item.image }}
{{ item.tags }}
```

### `{{ collection }}`
The collection name from the `from` parameter:
```twig
{% if collection == 'blog' %}
    <div class="blog-specific-content">...</div>
{% endif %}
```

## Grid Helper Methods

Access via `cms.grid.*` for formatted HTML output:

### Date Formatting
```twig
{{ cms.grid.date(item.date, 'relative') }}  {# "2 days ago" #}
{{ cms.grid.date(item.date, 'short') }}     {# "Mar 15, 2024" #}
```

### Text Excerpts
```twig
{{ cms.grid.excerpt(item.summary, 150) }}   {# Truncated with HTML wrapper #}
```

### Tag Lists
```twig
{{ cms.grid.tags(item.tags) }}              {# Formatted tag list #}
{{ cms.grid.tags(item.tags, '/tag/') }}     {# With links #}
```

### Price Formatting
```twig
{{ cms.grid.price(item.price) }}            {# $19.99 with HTML wrapper #}
```

### Meta Information
```twig
{{ cms.grid.meta(item.author) }}            {# Formatted meta data #}
```

## Image Handling

Use `cms.imageFromData()` with the collection context:
```twig
{% if item.image %}
    {{ cms.imageFromData(item.image, item.id, {w: 400}, {collection: collection}) }}
{% endif %}
```

## Built-in Templates

### Blog Template (`grid/blog.twig`)
- Displays image, title, date, summary, and tags
- Optimized for blog posts and articles

### Feed Template (`grid/feed.twig`)
- Shows date, title, content excerpt
- Designed for news feeds and updates

### Generic Template (`grid/generic.twig`)
- Basic image, title, and summary layout
- Works with any collection type

## CSS Grid Classes

The grid system includes comprehensive CSS with design system variables:

### Layout Classes
- `compact` - Smaller cards and gaps
- `wide` - Larger cards and spacing
- `list` - Single column layout
- `masonry` - Masonry-style layout

### Spacing Classes
- `gap-sm`, `gap-md`, `gap-lg`, `gap-xl` - Control grid gaps
- `padding-sm`, `padding-md`, `padding-lg` - Control card padding

### Style Classes
- `no-border` - Remove card borders
- `no-shadow` - Remove shadows and hover effects
- `flat` - Completely flat styling

## Real-World Examples

### E-commerce Products
```twig
{% cmsgrid cms.objects('products') from 'products' with 'products grid compact' %}
    {% if item.image %}
        {{ cms.imageFromData(item.image, item.id, {w: 300, h: 300}, {collection: collection}) }}
    {% endif %}
    <h4>{{ item.name }}</h4>
    <p class="price">{{ cms.grid.price(item.price) }}</p>
    {% if item.sale_price %}
        <p class="sale">{{ cms.grid.price(item.sale_price) }}</p>
    {% endif %}
{% endcmsgrid %}
```

### Team Members
```twig
{% cmsgrid cms.objects('team') from 'team' with 'team grid' %}
    {{ cms.imageFromData(item.photo, item.id, {w: 200, h: 200}, {collection: collection}) }}
    <h3>{{ item.name }}</h3>
    <p class="role">{{ item.position }}</p>
    <p>{{ cms.grid.excerpt(item.bio, 100) }}</p>
{% endcmsgrid %}
```

### News Feed
```twig
{% cmsgrid cms.objects('news') from 'feed' with 'feed list' %}
    {% include 'grid/feed.twig' %}
{% endcmsgrid %}
```

## Advanced Usage

### Conditional Templates
```twig
{% cmsgrid cms.objects('mixed') from collection_name %}
    {% if collection == 'blog' %}
        {% include 'grid/blog.twig' %}
    {% elseif collection == 'products' %}
        <h4>{{ item.name }}</h4>
        <p>{{ cms.grid.price(item.price) }}</p>
    {% else %}
        {% include 'grid/generic.twig' %}
    {% endif %}
{% endcmsgrid %}
```

### Dynamic Collection Names
```twig
{% set collection_type = get.type|default('blog') %}
{% cmsgrid cms.objects(collection_type) from collection_type with 'responsive-grid' %}
    {# Template adapts based on collection type #}
{% endcmsgrid %}
```

## Best Practices

1. **Use `from` parameter** - Always specify collection for proper image handling
2. **Leverage helper methods** - Use `cms.grid.*` for consistent formatting
3. **Include built-in templates** - Saves time and ensures consistency
4. **Combine CSS classes** - Mix layout, spacing, and style classes as needed
5. **Handle empty states** - Check for content before displaying

The `{% cmsgrid %}` tag provides a flexible, powerful system for displaying content grids while maintaining design consistency and providing helpful formatting utilities.