---
title: "Field Settings"
description: "Image validation rules, date field natural language defaults, and ID auto-generation configuration."
---

# Field Settings

## Image Validation

```twig
{{ cms.form.image("myimage", {}, {
    settings: {
        rules: {
            size: {min: 0, max: 300},        # File size in KB
            height: {min: 500, max: 1000},   # Height in pixels
            width: {min: 500, max: 1000},    # Width in pixels
            count: {max: 10},                # Max number of images
            orientation: 'landscape',         # 'landscape', 'portrait', or 'square'
            aspectratio: '4:3',              # Aspect ratio
            filetype: ['image/jpeg', 'image/png'],  # Allowed MIME types
            filename: ['image.jpg']          # Specific filename requirements
        }
    }
}) }}
```

## Date Field Natural Language Defaults

Date fields support natural language default values powered by CakePHP Chronos. This makes it easy to set smart defaults without complex date calculations.

### Using in Forms

```twig
{# Basic date field with tomorrow as default #}
{{ cms.form.date('event-date', {}, {
    default: 'tomorrow',
    label: 'Event Date'
}) }}

{# Date field with relative default #}
{{ cms.form.date('deadline', {}, {
    default: '+1 week',
    label: 'Project Deadline'
}) }}

{# Using with form builder #}
{% set form = cms.form.builder('tasks') %}
{{ form.field('due_date', {
    field: 'date',
    default: 'next friday',
    label: 'Due Date'
}) }}
```

### Supported Formats

```twig
{# Relative dates #}
default: 'now'              {# Current date/time #}
default: 'today'            {# Today at midnight #}
default: 'tomorrow'         {# Tomorrow #}
default: 'yesterday'        {# Yesterday #}

{# Relative intervals #}
default: '+1 day'           {# 1 day from now #}
default: '+2 weeks'         {# 2 weeks from now #}
default: '+3 months'        {# 3 months from now #}
default: '+1 year'          {# 1 year from now #}
default: '-7 days'          {# 7 days ago #}
default: '-1 month'         {# 1 month ago #}

{# Natural language #}
default: 'next monday'      {# Next Monday #}
default: 'last friday'      {# Last Friday #}
default: 'first day of this month'
default: 'last day of this month'
default: 'first day of next month'
default: 'next saturday 2:00 PM'
```

### Schema Definition

When defining date fields in schemas, you can use natural language defaults:

```json
{
    "type": "date",
    "label": "Event Date",
    "default": "tomorrow"
}

{
    "type": "date",
    "label": "Deadline",
    "default": "+30 days"
}

{
    "type": "date",
    "label": "Review Date",
    "default": "first day of next month"
}
```

### Practical Examples

```twig
{# Event creation form with smart defaults #}
{% set form = cms.form.builder('events') %}

{{ form.field('start_date', {
    field: 'date',
    default: 'next saturday',
    label: 'Event Start Date'
}) }}

{{ form.field('registration_deadline', {
    field: 'date',
    default: '-1 week',  {# 1 week before event #}
    label: 'Registration Deadline',
    help: 'Default is 1 week before event'
}) }}

{{ form.field('early_bird_deadline', {
    field: 'date',
    default: '-2 weeks',  {# 2 weeks before event #}
    label: 'Early Bird Deadline'
}) }}

{# Task management with dynamic defaults #}
{{ cms.form.date('task-due', {}, {
    default: '+3 days',
    label: 'Task Due Date',
    help: 'Default is 3 days from today'
}) }}

{# Subscription renewal #}
{{ cms.form.date('renewal-date', {}, {
    default: '+1 year',
    label: 'Renewal Date',
    help: 'Annual subscription renewal'
}) }}
```

### onCreate/onUpdate Settings

Date fields can be configured to automatically update:

```json
{
    "type": "date",
    "label": "Created Date",
    "settings": {
        "onCreate": true
    }
}

{
    "type": "date",
    "label": "Last Modified",
    "settings": {
        "onUpdate": true
    }
}
```

## Lock on Edit

Prevent a field from being changed after the initial object creation. When `lockOnEdit` is enabled, the field is editable on new objects but becomes readonly and disabled when editing an existing object. This works on all field types that use standard HTML inputs: text, number, select, date, textarea, etc.

### Schema Definition

```json
{
    "title": {
        "field": "text",
        "label": "Title",
        "settings": {
            "lockOnEdit": true
        }
    }
}
```

### Using in Forms

```twig
{{ cms.form.text('slug', {}, {
    label: 'URL Slug',
    settings: { lockOnEdit: true }
}) }}

{{ cms.form.select('category', {}, {
    label: 'Category',
    settings: { lockOnEdit: true },
    options: ['news', 'blog', 'tutorial']
}) }}
```

> **Note:** Both `readonly` and `disabled` HTML attributes are set because `readonly` does not apply to `<select>` elements per the HTML spec. Values from locked fields are still included when saving because the form collects data via JavaScript, not HTML form submission.

## ID Auto-generation

Configure automatic ID generation based on other field values:

```json
{
    "autogen": "${title}"
}
```

### Special autogen variables:

* `now` - Current date/time
* `timestamp` - Unix timestamp
* `uuid` - Unique identifier

```json
{
    "autogen": "${title}-${now}"
}
```
