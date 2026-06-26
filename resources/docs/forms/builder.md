---
title: "Form Builder"
description: "Create custom forms with the form builder, simple forms, and standalone buttons."
related:
  - forms/fields
  - forms/options
  - forms/patterns
  - forms/specialized
---

# Form Builder

The form builder provides the most flexibility for creating custom forms.

## Basic Usage

```twig
{# Create a form builder instance #}
{% set form = cms.form.builder('mycollection') %}

{# Add fields to the form #}
{% do form.addField('title') %}
{% do form.addField('content', {field: 'styledtext'}) %}
{% do form.addField('date') %}

{# Build and render the form #}
{{ form.build() }}
```

## Advanced Example

```twig
{# Create a complex form with custom layout #}
{% set form = cms.form.builder('products', {
    id: 'my-product-id',
    hideID: false,
    save: 'Save Product',
    delete: 'Delete Product'
}) %}

{# Build form content in columns #}
{% set col1 = form.field('id') %}
{% set col1 = col1 ~ form.field('name') %}
{% set col1 = col1 ~ form.field('description', {field: 'styledtext'}) %}
{% set col1 = col1 ~ form.field('price', {field: 'number'}) %}

{% set col2 = form.field('category', {field: 'select'}) %}
{% set col2 = col2 ~ form.field('tags', {field: 'list'}) %}
{% set col2 = col2 ~ form.field('featured', {field: 'toggle'}) %}
{% set col2 = col2 ~ form.field('image') %}

{# Create two-column layout #}
{% set layout = form.layout2Columns(col1, col2) %}

{# Build form with custom layout #}
{{ form.build(layout) }}
```

## Form Buttons

```twig
{# Standalone buttons #}
{{ cms.form.save('Save Changes') }}
{{ cms.form.delete('Remove Item') }}
```

## Simple Forms

For basic form submission without full object management:

```twig
{# Create a simple form that posts to a route #}
{{ cms.form.simple('/api/contact', '<input name="email" type="email" required>', {
    method: 'POST',
    label: 'Send Message',
    refresh: true
}) }}
```

### Simple Form Options

```twig
{{ cms.form.simple('/api/contact', content, {
    method: 'POST',                # HTTP method (string, default: 'POST')
    label: 'Send Message',         # Submit button label (string)
    refresh: true,                 # Refresh page after submission (bool, default: false)
    class: 'contact-form',         # CSS classes (string, default: '')
    csrfManager: csrfManager       # CSRF token manager instance
}) }}
```

## Fieldsets

Use `cms.form.fieldset()` to group fields inside a styled fieldset container. Capture your rendered fields with a `{% set %}` block and pass them as the content:

```twig
{% set inner %}
  {{ cms.form.field("text", "first_name", { label: "First name" }) }}
  {{ cms.form.field("text", "last_name",  { label: "Last name" }) }}
{% endset %}

{{ cms.form.fieldset("Contact", inner, { formgrid: "first_name last_name" }) }}
```

### Fieldset Options

- **legend** (string, optional): The fieldset legend text. Leave empty for no legend.
- **content** (string): Pre-rendered field HTML (typically captured with `{% set %}`).
- **options** (object, optional):
  - `formgrid` (string): Inner grid layout string using the same row syntax as schema `formgrid` definitions.
  - `class` (string): Extra CSS classes to add to the fieldset element.

### Example with Inner Grid

```twig
{% set contact_fields %}
  {{ cms.form.field("text", "email", { label: "Email" }) }}
  {{ cms.form.field("text", "phone", { label: "Phone" }) }}
{% endset %}

{{ cms.form.fieldset("Contact", contact_fields, {
    formgrid: "email phone",
    class: "contact-section"
}) }}
```

This produces the same `.form-grid-fieldset` markup as the schema `[[ ]]` syntax.

## Blog Form Options

```twig
{{ cms.form.blog({
    collection: 'blog',
    save: 'Save Post',
    delete: 'Delete Post',
    fields: {
        date: true,
        summary: true,
        content: true,
        author: true,
        tags: true,
        featured: true,
        draft: true,
        image: true,
        categories: false,
        extra: false,
        extra2: false,
        media: false,
        genre: false,
        labels: false,
        archived: false,
        gallery: false
    }
}) }}
```
