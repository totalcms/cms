---
title: "CMS Grid Tag Reference"
description: "Display Total CMS collections in customizable grid layouts using the cmsgrid Twig tag with built-in CSS classes, helper methods, and templates."
---

# CMS Grid Tag Reference

The `{% cmsgrid %}` tag provides a powerful way to display collections of content in customizable grid layouts with built-in styling and helper methods.

## Syntax

```twig
{% cmsgrid objects from 'collection' with 'classes' as 'tag' %}
    {# Template content with {{ object }} and {{ collection }} variables #}
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
{% cmsgrid cms.collection.objects('blog') from 'blog' with 'blog compact' %}
    <h3>{{ object.title }}</h3>
    <p>{{ object.summary }}</p>
    <time>{{ cms.render.grid.date(object.date) }}</time>
{% endcmsgrid %}
```

### Using Built-in Templates
```twig
{% cmsgrid cms.collection.objects('blog') from 'blog' with 'blog list' %}
    {% include 'grid/blog.twig' %}
{% endcmsgrid %}
```

### Custom Item Tags
```twig
{% cmsgrid cms.collection.objects('products') from 'products' with 'products grid' as 'article' %}
    <h4>{{ object.name }}</h4>
    <span class="price">{{ object.price|price }}</span>
{% endcmsgrid %}
```

## Available Variables

### `{{ object }}`
Each object in the collection array with all its properties:
```twig
{{ object.title }}
{{ object.summary }}
{{ object.date }}
{{ object.image }}
{{ object.tags }}
```

### `{{ collection }}`
The collection name from the `from` parameter:
```twig
{% if collection == 'blog' %}
    <div class="blog-specific-content">...</div>
{% endif %}
```

## Grid Helper Methods

Access via `cms.render.grid.*` for formatted HTML output:

### Date Formatting
```twig
{{ cms.render.grid.date(object.date, 'relative') }}  {# "2 days ago" #}
{{ cms.render.grid.date(object.date, 'short') }}     {# "Mar 15, 2024" #}
```

### Text Excerpts
```twig
{{ cms.render.grid.excerpt(object.summary, 150) }}   {# Truncated with HTML wrapper #}
```

### Tag Lists
```twig
{{ cms.render.grid.tags(object.tags) }}              {# Formatted tag list #}
{{ cms.render.grid.tags(object.tags, '/tag/') }}     {# With links #}
```

### Price Formatting
```twig
{{ cms.render.grid.price(object.price) }}            {# $19.99 with HTML wrapper #}
```

### Meta Information
```twig
{{ cms.render.grid.meta(object.author) }}            {# Formatted meta data #}
```

## Image Handling

Use `cms.render.image()` with the object and collection context:
```twig
{% if object.image %}
    {{ cms.render.image(object, {w: 400}, {collection: collection, property: 'image'}) }}
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

### Item Classes
- `cms-grid-item-full` - Makes an item span the full width of the grid (all columns). Useful for featured items, section headers, or full-width content within a grid.

```twig
{% cmsgrid cms.collection.objects('blog') from 'blog' with 'blog' %}
    {% if loop.first %}
        {# Featured post spans full width #}
        <div class="cms-grid-item cms-grid-item-full">
            <h2>{{ object.title }}</h2>
            <p>{{ object.summary }}</p>
        </div>
    {% else %}
        <h3>{{ object.title }}</h3>
        <p>{{ object.summary }}</p>
    {% endif %}
{% endcmsgrid %}
```

Note: A `.cms-load-more` button placed as the last child inside a `cms-grid` will automatically span the full width and center itself.

## CSS Variables

Override these variables on `.cms-grid` or a custom class to customize the grid appearance.

### Grid Layout

| Variable                               | Default | Description |
|----------------------------------------|---------|-------------|
| `--cms-grid-gap`                       | `1rem` | Gap between grid items |
| `--cms-grid-min-column-width`          | `250px` | Minimum column width for auto-fill columns |
| `--cms-grid-columns`                   | `repeat(auto-fill, minmax(..., 1fr))` | Grid column template |
| `--cms-grid-align-items`               | `stretch` | Vertical alignment of grid items |
| `--cms-grid-justify-items`             | `stretch` | Horizontal alignment of grid items |

### Card Styling

| Variable                               | Default | Description |
|----------------------------------------|---------|-------------|
| `--cms-card-padding`                   | `1rem` | Padding inside each card |
| `--cms-card-bg`                        | `#ffffff` | Card background color |
| `--cms-card-border`                    | `1px solid oklch(...)` | Card border |
| `--cms-card-border-radius`             | `var(--totalform-radius)` | Card corner radius |
| `--cms-card-shadow`                    | `0 1px 3px oklch(0.2 0 0 / 0.1)` | Card box shadow |
| `--cms-card-hover-shadow`              | `0 4px 12px oklch(0.2 0 0 / 0.15)` | Card shadow on hover |
| `--cms-card-transition`                | `all 0.2s ease` | Card hover transition |

### Typography

| Variable                               | Default | Description |
|----------------------------------------|---------|-------------|
| `--cms-text-color`                     | `oklch(var(--totalform-text-color))` | Body text color |
| `--cms-heading-color`                  | `oklch(var(--totalform-darkgray))` | Heading color |
| `--cms-meta-color`                     | `oklch(var(--totalform-lightgray))` | Meta/secondary text color |
| `--cms-link-color`                     | `oklch(var(--totalform-accent))` | Link color |

### Spacing & Images

| Variable                               | Default | Description |
|----------------------------------------|---------|-------------|
| `--cms-item-spacing`                   | `0.5rem` | Spacing between elements inside a card |
| `--cms-image-aspect-ratio`             | `16 / 9` | Aspect ratio for featured images |
| `--cms-image-border-radius`            | `calc(var(--cms-card-border-radius) * 0.5)` | Image corner radius |

### Example: Custom Grid Theme

```css
.my-blog-grid {
    --cms-grid-min-column-width: 300px;
    --cms-grid-gap: 2rem;
    --cms-card-bg: #f9f9f9;
    --cms-card-border: none;
    --cms-card-shadow: none;
    --cms-card-hover-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    --cms-image-aspect-ratio: 3 / 2;
    --cms-card-padding: 1.5rem;
}
```

```twig
{% cmsgrid cms.collection.objects('blog') from 'blog' with 'my-blog-grid' %}
    {% include 'grid/blog.twig' %}
{% endcmsgrid %}
```

## Real-World Examples

### E-commerce Products
```twig
{% cmsgrid cms.collection.objects('products') from 'products' with 'products grid compact' %}
    {% if object.image %}
        {{ cms.render.image(object, {w: 300, h: 300}, {collection: collection, property: 'image'}) }}
    {% endif %}
    <h4>{{ object.name }}</h4>
    <p class="price">{{ cms.render.grid.price(object.price) }}</p>
    {% if object.sale_price %}
        <p class="sale">{{ cms.render.grid.price(object.sale_price) }}</p>
    {% endif %}
{% endcmsgrid %}
```

### Team Members
```twig
{% cmsgrid cms.collection.objects('team') from 'team' with 'team grid' %}
    {{ cms.render.image(object, {w: 200, h: 200}, {collection: collection, property: 'photo'}) }}
    <h3>{{ object.name }}</h3>
    <p class="role">{{ object.position }}</p>
    <p>{{ cms.render.grid.excerpt(object.bio, 100) }}</p>
{% endcmsgrid %}
```

### News Feed
```twig
{% cmsgrid cms.collection.objects('news') from 'feed' with 'feed list' %}
    {% include 'grid/feed.twig' %}
{% endcmsgrid %}
```

## Advanced Usage

### Conditional Templates
```twig
{% cmsgrid cms.collection.objects('mixed') from collection_name %}
    {% if collection == 'blog' %}
        {% include 'grid/blog.twig' %}
    {% elseif collection == 'products' %}
        <h4>{{ object.name }}</h4>
        <p>{{ cms.render.grid.price(object.price) }}</p>
    {% else %}
        {% include 'grid/generic.twig' %}
    {% endif %}
{% endcmsgrid %}
```

### Dynamic Collection Names
```twig
{% set collection_type = get.type|default('blog') %}
{% cmsgrid cms.collection.objects(collection_type) from collection_type with 'responsive-grid' %}
    {# Template adapts based on collection type #}
{% endcmsgrid %}
```

## Best Practices

1. **Use `from` parameter** - Always specify collection for proper image handling
2. **Leverage helper methods** - Use `cms.render.grid.*` for consistent formatting
3. **Include built-in templates** - Saves time and ensures consistency
4. **Combine CSS classes** - Mix layout, spacing, and style classes as needed
5. **Handle empty states** - Check for content before displaying

The `{% cmsgrid %}` tag provides a flexible, powerful system for displaying content grids while maintaining design consistency and providing helpful formatting utilities.