# Twig Overview

Total CMS uses Twig as its templating engine, providing a powerful, secure, and designer-friendly way to create dynamic templates. This guide covers how Twig is integrated into Total CMS and how to use it effectively.

## What is Twig?

Twig is a modern template engine for PHP that offers:

- **Clean syntax** - Easy to read and write
- **Secure** - Automatic output escaping
- **Fast** - Compiled templates with caching
- **Flexible** - Extensible with custom functions and filters
- **Designer-friendly** - No PHP knowledge required

## Basic Syntax

### Variables

Display variables using double curly braces:

```twig
{{ variable }}
{{ user.name }}
{{ product['price'] }}
{{ items[0] }}
```

### Comments

```twig
{# This is a comment and won't appear in the output #}
```

### Tags

Control structures use curly braces with percent signs:

```twig
{% if cms.userLoggedIn %}
    Welcome, {{ cms.userData.name }}!
{% endif %}

{% for item in items %}
    <li>{{ item.title }}</li>
{% endfor %}
```

## Total CMS Integration

The most important Twig variable in Total CMS is `cms`, which fetches content from the CMS:

```twig
{# Get all items from a collection #}
{% set posts = cms.objects('blog') %}

{# Get a specific item by ID #}
{% set coolguy = cms.object('users', 'joeworkman') %}
```

For more information, check out the [Total CMS Content with Twig](docs/twig-totalcms) docs.


<!--

## Template Structure

### Basic Page Template

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ page.title | default('My Site') }}</title>
    {{ include('partials/head.twig') }}
</head>
<body>
    {% include 'partials/header.twig' %}

    <main>
        {% block content %}
            {# Page content goes here #}
        {% endblock %}
    </main>

    {% include 'partials/footer.twig' %}
</body>
</html>
```

### Template Inheritance

Create a base layout:

```twig
{# layouts/base.twig #}
<!DOCTYPE html>
<html>
<head>
    <title>{% block title %}Default Title{% endblock %}</title>
    {% block head %}{% endblock %}
</head>
<body>
    {% block content %}{% endblock %}
    {% block scripts %}{% endblock %}
</body>
</html>
```

Extend the base layout:

```twig
{# pages/home.twig #}
{% extends 'layouts/base.twig' %}

{% block title %}Home - My Site{% endblock %}

{% block content %}
    <h1>Welcome</h1>
    {{ content | markdown }}
{% endblock %}

-->

## Working with Collections

### Displaying Blog Posts

```twig
{% set posts = cms.objects('blog') %}

<div class="blog-posts">
    {% for post in posts %}
        <article>
            <h2>{{ post.title }}</h2>
            <time>{{ post.date | date('F j, Y') }}</time>
            <div class="content">
                {{ post.content | markdown }}
            </div>
            {% if post.tags %}
                <div class="tags">
                    {% for tag in post.tags %}
                        <span class="tag">{{ tag }}</span>
                    {% endfor %}
                </div>
            {% endif %}
        </article>
    {% endfor %}
</div>
```

### Image Galleries

```twig
{% set gallery = cms.gallery('portfolio') %}

<div class="gallery">
    <h2>{{ gallery.title }}</h2>
    <div class="grid">
        {% for image in gallery.images %}
            <figure>
                <img src="{{ image.url | resize(400, 300) }}"
                     alt="{{ image.alt }}"
                     loading="lazy">
                <figcaption>{{ image.caption }}</figcaption>
            </figure>
        {% endfor %}
    </div>
</div>
```

### Conditional Content

```twig
{% set feature = api('toggle', 'show-newsletter') %}

{% if cms.toggle('feature') %}
    <div class="newsletter">
        {% include 'partials/newsletter-signup.twig' %}
    </div>
{% endif %}
```

## Filters

Total CMS adds many custom filters to Twig:

### Text Filters

```twig
{{ text | markdown }}              {# Convert markdown to HTML #}
{{ text | nl2br }}                 {# Convert newlines to <br> #}
{{ text | truncate(100) }}         {# Truncate to 100 characters #}
{{ text | title }}                 {# Title Case Text #}
{{ text | slug }}                  {# convert-to-slug #}
```

### Date Filters

```twig
{{ post.date | date('F j, Y') }}           {# January 15, 2024 #}
{{ post.date | date('Y-m-d') }}            {# 2024-01-15 #}
{{ post.date | time_ago }}                 {# 2 hours ago #}
{{ post.date | date_modify('+1 day') }}    {# Add one day #}
```

### Array/Object Filters

```twig
{{ items | first }}                        {# Get first item #}
{{ items | last }}                         {# Get last item #}
{{ items | random }}                       {# Get random item #}
{{ items | slice(0, 3) }}                  {# Get first 3 items #}
{{ items | filter(v => v.active) }}        {# Filter active items #}
{{ items | map(v => v.name) }}             {# Extract names #}
```

## Functions

### Utility Functions

```twig
{{ random(1, 100) }}                       {# Random number #}
{{ "now" | date('Y') }}                    {# Current year #}
{{ dump(variable) }}                       {# Debug variable #}
```

## Best Practices

### 1. Use Template Caching

Total CMS automatically caches compiled templates. Clear cache when making large changes.

[Go to Cache Cleaner](utils/cache-cleaner)

### 2. Escape Output

Twig will automatically render HTML. If you want to display the raw HTML stored in the CMS you can.

```twig
{{ html_content | e }}
```


## Advanced Features

### Global Variables

Access global variables in all templates:

```twig
{{ cms.api }}
{{ cms.dashboard }}
{{ cms.logout }}
{{ cms.domain }}
```

### Template Debugging

Enable debug mode to use the `dump()` function:

```twig
{{ dump() }}               {# Dump all variables #}
{{ dump(user) }}           {# Dump specific variable #}
```

## Error Handling

### Check if Variables Exist

```twig
{% if post is defined %}
    {{ post.title }}
{% endif %}

{# Or use default filter #}
{{ post.title | default('Untitled') }}
```

### Try-Catch Blocks

```twig
{% try %}
    {{ risky_operation() }}
{% catch %}
    <p>An error occurred</p>
{% endtry %}
```

## Integration with JavaScript

### Passing Data to JavaScript

```twig
<script>
    window.appData = {
        user: {{ user | json_encode }},
        apiUrl: "{{ api_url }}",
        csrfToken: "{{ csrf_token() }}"
    };
</script>
```

### Dynamic JavaScript

```twig
<script>
    const items = [
        {% for item in items %}
            {
                id: {{ item.id }},
                name: "{{ item.name | e('js') }}"
            }{% if not loop.last %},{% endif %}
        {% endfor %}
    ];
</script>
```

## Resources

- [Official Twig Documentation](https://twig.symfony.com/doc/)
- [Total CMS Filters Reference](docs/twig-filters)
- [Total CMS Functions Reference](docs/twig-functions)
- [Total CMS Form Helpers](docs/twig-forms)

Remember: Twig makes your templates more maintainable, secure, and easier to work with. Take advantage of its features to create clean, efficient templates!