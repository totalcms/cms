---
title: "Twig Conditionals"
description: "Master Twig conditional statements in Total CMS for comparisons, existence checks, array and string tests, date logic, and CMS-specific conditions."
---

# Twig Conditionals

Complete guide to conditional statements in Total CMS Twig templates for controlling template flow based on dynamic data.

## Overview

Conditional statements allow you to control the flow of your templates based on dynamic data. In Twig, you can use the `{% if %}` tag to test conditions and display content accordingly.

**Basic syntax:**

```twig
{% if condition %}
    Content to display if true
{% endif %}
```

**With else:**

```twig
{% if condition %}
    Content when true
{% else %}
    Content when false
{% endif %}
```

**With elseif:**

```twig
{% if condition1 %}
    Content for condition 1
{% elseif condition2 %}
    Content for condition 2
{% else %}
    Default content
{% endif %}
```

## Basic Comparisons

### Has Value (Truthy Test)

Tests if a variable has a "truthy" value. For booleans, it checks true/false. For variables, it returns false if undefined, null, empty string, empty array, or the number 0.

```twig
{% if post.draft %}
    This post is a draft
{% endif %}
```

### Not (Negation)

Inverts the condition - returns true when the value is false or falsy.

```twig
{% if not post.featured %}
    This post is not featured
{% endif %}
```

### String Equality

Checks if a string value exactly matches another string.

```twig
{% if page.name == "home" %}
    Welcome to the home page
{% endif %}
```

### Boolean Comparison

Explicitly compares a boolean value. Can use `== true` or `== false`.

```twig
{% if blog.featured == true %}
    This is a featured post
{% endif %}
```

### Numeric Comparisons

Compare numbers using operators: `>`, `<`, `>=`, `<=`, `==`, `!=`

```twig
{% if product.inventory >= 10 %}
    In stock
{% elseif product.inventory > 0 %}
    Low stock
{% else %}
    Out of stock
{% endif %}
```

### Not Equal

Checks if values are not equal using `!=` operator.

```twig
{% if user.role != "guest" %}
    Welcome back, member!
{% endif %}
```

## Existence & Type Checks

### Variable Defined

Check if a variable has been defined in the template context.

```twig
{% if myobject is defined %}
    Object exists
{% endif %}

{% if myobject is not defined %}
    Object does not exist
{% endif %}
```

### Null Check

Check if a variable is null (explicitly set to null, not just undefined).

```twig
{% if user.avatar is null %}
    <img src="default-avatar.png">
{% else %}
    <img src="{{ user.avatar }}">
{% endif %}
```

### Empty Check

Tests if a variable is empty (empty string, empty array, or false).

```twig
{% if object.tags is empty %}
    No tags available
{% else %}
    {% for tag in object.tags %}
        {{ tag }}
    {% endfor %}
{% endif %}
```

### File Exists

Check if a file exists in your Total CMS file system.

```twig
{% if fileExists('documents/report.pdf') %}
    <a href="documents/report.pdf">Download Report</a>
{% endif %}
```

### Image Exists

Check if an image property exists for an object before attempting to display it.

```twig
{% if imageExists(post.image) %}
    {{ cms.render.image(post.id, {collection: 'blog', property: 'image'}) }}
{% else %}
    <img src="placeholder.jpg">
{% endif %}
```

## Combining Conditions

### OR - Either Condition

Returns true if *either* condition is true. You can chain multiple OR conditions.

```twig
{% if user.role == "editor" or user.role == "admin" %}
    You have editing permissions
{% endif %}
```

### AND - Both Conditions

Returns true only if *all* conditions are true. You can chain multiple AND conditions.

```twig
{% if post.published == true and post.date < "now"|date %}
    This post is live
{% endif %}
```

### Complex Combinations

Use parentheses to group conditions and control evaluation order.

```twig
{% if (user.role == "admin" or user.role == "editor") and post.draft == false %}
    Show edit button
{% endif %}
```

## Array & Collection Tests

### Value in Array

Check if a value exists within an array of values.

```twig
{% if post.category in ["news", "updates", "announcements"] %}
    This is a news-related post
{% endif %}
```

### Array Contains Value (Reverse)

Check if an array variable contains a specific value.

```twig
{% if "admin" in user.groups %}
    Administrator access granted
{% endif %}
```

### Array Length

Check the number of items in an array using the `length` filter.

```twig
{% if post.tags|length > 0 %}
    Tags: {{ post.tags|join(', ') }}
{% endif %}

{% if gallery.images|length >= 10 %}
    This gallery has {{ gallery.images|length }} images
{% endif %}
```

### Collection Has Objects

Check if a collection has any objects before looping.

```twig
{% set posts = cms.collection.objects('blog') %}
{% if posts|length > 0 %}
    {% for post in posts %}
        {{ post.title }}
    {% endfor %}
{% else %}
    No blog posts found
{% endif %}
```

### Iterable Test

Check if a variable can be iterated over (arrays, objects, etc.).

```twig
{% if items is iterable %}
    {% for item in items %}
        {{ item }}
    {% endfor %}
{% endif %}
```

## String Operations

### Starts With

Check if a string begins with a specific substring.

```twig
{% if product.sku starts with "PROD-" %}
    Standard product
{% endif %}
```

### Ends With

Check if a string ends with a specific substring.

```twig
{% if user.email ends with "@example.com" %}
    Internal user account
{% endif %}
```

### Contains (Using 'in')

Check if a string contains a substring. Use `|lower` for case-insensitive matching.

```twig
{% if "urgent" in post.title|lower %}
    <span class="badge urgent">Urgent</span>
{% endif %}
```

### Regular Expression Match

Test if a string matches a regular expression pattern. Use double backslashes for escape sequences.

```twig
{% if user.phone matches '/^[\\d\\.\\-\\(\\)\\s]+$/' %}
    Valid phone format
{% endif %}

{% if post.title matches '/\\d{4}/' %}
    Title contains a year
{% endif %}
```

## Date & Time Comparisons

### Date Before Now

Compare a date field to the current date/time.

```twig
{% if event.date < "now"|date %}
    This event has passed
{% endif %}
```

### Date After Specific Date

Compare a date to a specific date string.

```twig
{% if post.date > "2024-01-01"|date %}
    Posted this year
{% endif %}
```

### Date Range

Check if a date falls within a specific range. Use modifiers like `+7 days`, `-1 month`, etc.

```twig
{% if event.date >= "now"|date and event.date <= "+7 days"|date %}
    Happening this week
{% endif %}
```

### Date Between Two Dates

Store dates in variables for cleaner comparisons.

```twig
{% set startDate = "2024-06-01"|date %}
{% set endDate = "2024-08-31"|date %}
{% if event.date >= startDate and event.date <= endDate %}
    Summer event
{% endif %}
```

## Total CMS Specific Tests

### Object Exists

Check if an object exists before using it.

```twig
{% set page = cms.collection.object('pages', 'about') %}
{% if page %}
    {{ page.content }}
{% else %}
    Page not found
{% endif %}
```

### Toggle State

Check a toggle's state from your Total CMS settings.

```twig
{% if cms.toggle('maintenance-mode') %}
    <div class="alert">Site under maintenance</div>
{% endif %}
```

### Config Value

Access and test configuration values.

```twig
{% if cms.config('debug') == true %}
    Debug mode is enabled
{% endif %}
```

### Environment Check

Check which environment the site is running in.

```twig
{% if cms.env == 'development' %}
    <div class="debug-bar">Development Mode</div>
{% endif %}
```

### User Authentication

Check if a user is logged in.

```twig
{% if cms.user %}
    Welcome back, {{ cms.user.name }}!
{% else %}
    <a href="/login">Login</a>
{% endif %}
```

### User Permission

Check if the logged-in user has specific roles or permissions.

```twig
{% if cms.user and "editor" in cms.user.roles %}
    <a href="/admin/edit">Edit Content</a>
{% endif %}
```

## Advanced Tests

### Default Values

Provide a default value if the variable is undefined or empty.

```twig
{% if product.rating|default(0) >= 4 %}
    Highly rated!
{% endif %}
```

### Even/Odd Tests

Test if a number is even or odd. Useful for alternating row styles.

```twig
{% for post in posts %}
    <div class="{% if loop.index is even %}even{% else %}odd{% endif %}">
        {{ post.title }}
    </div>
{% endfor %}
```

### Divisible By

Check if a number is divisible by another number. Great for grid layouts.

```twig
{% for item in items %}
    {{ item.name }}
    {% if loop.index is divisible by(3) %}
        </div><div class="row">
    {% endif %}
{% endfor %}
```

### Same As

Check if two variables reference the exact same object (identity check, not equality).

```twig
{% if currentPage is same as(homePage) %}
    <span class="active">Home</span>
{% endif %}
```

### Constant Check

Check if a value matches a defined constant.

```twig
{% if status is constant('STATUS_ACTIVE') %}
    Active status
{% endif %}
```

### Ternary Operator

Inline conditional using the ternary operator `condition ? true : false`.

```twig
{{ post.featured ? 'Featured Post' : 'Regular Post' }}

{% set cssClass = post.published ? 'published' : 'draft' %}
<div class="{{ cssClass }}">{{ post.title }}</div>
```

### Null Coalescing

Return the first defined value using the `??` operator. Similar to multiple `|default` filters.

```twig
{{ user.nickname ?? user.name ?? 'Guest' }}

{% set displayName = user.preferredName ?? user.firstName ?? user.username %}
```

## Loop-Specific Conditionals

### First Item

Check if current iteration is the first item in the loop.

```twig
{% for post in posts %}
    {% if loop.first %}
        <div class="featured">{{ post.title }}</div>
    {% else %}
        <div class="regular">{{ post.title }}</div>
    {% endif %}
{% endfor %}
```

### Last Item

Check if current iteration is the last item (useful for adding separators).

```twig
{% for item in items %}
    {{ item.name }}{% if not loop.last %}, {% endif %}
{% endfor %}
```

### Loop Index

Use `loop.index` (1-based) or `loop.index0` (0-based) to check position.

```twig
{% for post in posts %}
    {% if loop.index <= 3 %}
        <div class="top-post">{{ post.title }}</div>
    {% endif %}
{% endfor %}
```

### Loop Length

Access the total number of items being looped through.

```twig
{% for post in posts %}
    Post {{ loop.index }} of {{ loop.length }}
{% endfor %}
```

## Best Practices

### Always Check Object Existence

Always check if objects exist before accessing their properties to avoid errors:

```twig
{% set post = cms.collection.object('blog', 'my-post') %}
{% if post %}
    {{ post.title }}
{% endif %}
```

### Use Variables for Complex Conditions

Make complex conditions more readable by using variables:

```twig
{% set isAdmin = cms.user and "admin" in cms.user.roles %}
{% set isPublished = post.published and post.date <= "now"|date %}

{% if isAdmin or isPublished %}
    {{ post.content }}
{% endif %}
```

### Combine Existence and Value Checks

For safety, combine existence and value checks:

```twig
{% if post.gallery is defined and post.gallery is not empty %}
    {{ cms.render.gallery(post.id) }}
{% endif %}
```

### Performance Considerations

Avoid calling CMS functions multiple times in conditions:

```twig
{# Bad - calls function multiple times #}
{% if cms.collection.objects('blog')|length > 0 %}
    {{ cms.collection.objects('blog')|length }} posts
{% endif %}

{# Good - calls function once #}
{% set posts = cms.collection.objects('blog') %}
{% if posts|length > 0 %}
    {{ posts|length }} posts
{% endif %}
```

### Safe Image/File Access

Always check existence before accessing files or images:

```twig
{# Check if image exists #}
{% if imageExists(post.image) %}
    {{ cms.render.image(post.id, {property: 'hero'}) }}
{% endif %}

{# Check if file exists #}
{% if fileExists('downloads/brochure.pdf') %}
    <a href="downloads/brochure.pdf">Download</a>
{% endif %}
```

## Quick Reference

### Comparison Operators

| Operator | Description | Example |
|----------|-------------|---------|
| `==` | Equal to | `price == 10` |
| `!=` | Not equal to | `status != "draft"` |
| `<` | Less than | `age < 18` |
| `>` | Greater than | `count > 5` |
| `<=` | Less than or equal | `score <= 100` |
| `>=` | Greater than or equal | `rating >= 4` |

### Logical Operators

| Operator | Description | Example |
|----------|-------------|---------|
| `and` | Both conditions true | `published and featured` |
| `or` | Either condition true | `admin or editor` |
| `not` | Negate condition | `not deleted` |
| `()` | Group conditions | `(a or b) and c` |

### Tests

| Test | Description | Example |
|------|-------------|---------|
| `defined` | Variable exists | `var is defined` |
| `null` | Value is null | `value is null` |
| `empty` | Value is empty | `array is empty` |
| `even` | Number is even | `num is even` |
| `odd` | Number is odd | `num is odd` |
| `iterable` | Can be looped | `items is iterable` |
| `divisible by` | Divisible by number | `num is divisible by(3)` |
| `same as` | Same object | `a is same as(b)` |
| `constant` | Matches constant | `x is constant('Y')` |

### String Tests

| Test | Description | Example |
|------|-------------|---------|
| `starts with` | String starts with | `str starts with "hello"` |
| `ends with` | String ends with | `str ends with ".com"` |
| `matches` | Matches regex | `str matches '/\\d+/'` |
| `in` | Contains substring | `"test" in str` |

### Loop Variables

| Variable | Description | Example |
|----------|-------------|---------|
| `loop.index` | Current iteration (1-based) | `{{ loop.index }}` |
| `loop.index0` | Current iteration (0-based) | `{{ loop.index0 }}` |
| `loop.first` | True if first iteration | `{% if loop.first %}` |
| `loop.last` | True if last iteration | `{% if loop.last %}` |
| `loop.length` | Total items in loop | `{{ loop.length }}` |
| `loop.parent` | Parent loop context | `{{ loop.parent.index }}` |

## Common Patterns

### Safe Navigation

```twig
{# Check nested properties safely #}
{% if user is defined and user.profile is defined and user.profile.avatar %}
    {{ user.profile.avatar }}
{% endif %}

{# Or use default values #}
{{ user.profile.avatar|default('default.jpg') }}
```

### Multiple Fallbacks

```twig
{# Try multiple sources #}
{% set title = page.customTitle ?? page.title ?? 'Untitled' %}

{# Or with if statements #}
{% if page.customTitle %}
    {{ page.customTitle }}
{% elseif page.title %}
    {{ page.title }}
{% else %}
    Untitled
{% endif %}
```

### Conditional Classes

```twig
{# Single condition #}
<div class="post {{ post.featured ? 'featured' : '' }}">

{# Multiple conditions #}
<div class="post
    {% if post.featured %}featured{% endif %}
    {% if post.draft %}draft{% endif %}
    {% if post.urgent %}urgent{% endif %}">

{# Complex logic #}
{% set statusClass = post.published ? 'published' : (post.draft ? 'draft' : 'pending') %}
<div class="post {{ statusClass }}">
```

### Access Control

```twig
{# Check user permissions #}
{% set canEdit = cms.user and (
    "admin" in cms.user.roles or
    "editor" in cms.user.roles or
    cms.user.id == post.authorId
) %}

{% if canEdit %}
    <a href="/edit/{{ post.id }}">Edit</a>
{% endif %}
```

### Smart Defaults

```twig
{# Provide sensible defaults #}
{% set perPage = request.query.limit|default(10) %}
{% set sortBy = request.query.sort|default('date') %}
{% set order = request.query.order|default('desc') %}

{# Validate and constrain values #}
{% if perPage > 100 %}
    {% set perPage = 100 %}
{% endif %}
```
