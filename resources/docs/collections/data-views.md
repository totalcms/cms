---
title: "Data Views"
description: "Pre-compute and cache data structures from Total CMS collections using Twig templates. Data Views auto-rebuild when dependencies change."
---

# Data Views

Data Views let you pre-compute data structures from your collections using Twig templates. Instead of querying and transforming data on every page load, a Data View runs your Twig template once and caches the result. You can then access the pre-built data instantly in any template.

## Why Use Data Views?

- **Performance**: Expensive queries and aggregations are computed once, not on every request
- **Cross-Collection Data**: Combine data from multiple collections into a single structure
- **Automatic Updates**: Views rebuild automatically when their dependent collections change
- **Simple Access**: Retrieve pre-computed data with a single `cms.view.get('view-id')` call

## Creating a Data View

1. Navigate to **Data Views** in the admin sidebar
2. Click **New View**
3. Fill in the form fields:
   - **Name**: A descriptive name for the view
   - **ID**: Auto-generated slug used to reference the view in templates
   - **Description**: Optional notes about what the view does
   - **Dependencies**: Select which collections this view depends on
   - **Definition**: The Twig template that builds your data

### Writing a Definition

A definition is a Twig template that builds up a `data` variable. You have full access to the CMS context, so you can use `cms.collection.objects()`, `cms.collection.search()`, and any other CMS functions.

The template must set a `data` variable containing the structure you want to store. This variable is automatically serialized to JSON when the view is built.

#### Basic Example

A view that counts blog posts and collects their titles:

```twig
{% set posts = cms.collection.objects('blog') %}
{% set data = {
    "count": posts|length,
    "titles": posts|column('title')
} %}
```

#### Cross-Collection Example

A view that combines team members with their department names:

```twig
{% set members = cms.collection.objects('team') %}
{% set departments = cms.collection.objects('departments') %}

{% set deptMap = {} %}
{% for dept in departments %}
    {% set deptMap = deptMap|merge({ (dept.id): dept.name }) %}
{% endfor %}

{% set result = [] %}
{% for member in members %}
    {% set result = result|append({
        "name": member.name,
        "department": deptMap[member.departmentId] ?? 'Unknown'
    }) %}
{% endfor %}

{% set data = {
    "members": result,
    "total": result|length
} %}
```

#### Aggregation Example

A view that builds statistics for a dashboard:

```twig
{% set posts = cms.collection.objects('blog') %}
{% set products = cms.collection.objects('products') %}

{% set data = {
    "blog": {
        "total": posts|length,
        "published": posts|filter(p => p.published)|length
    },
    "products": {
        "total": products|length,
        "featured": products|filter(p => p.featured)|length
    }
} %}
```

## Dependencies

The **Dependencies** field tells Total CMS which collections your view reads from. When any object in a dependent collection is created, updated, or deleted, the view is automatically queued for rebuild.

Set your dependencies to match the collections used in your definition. For the cross-collection example above, you would select both `team` and `departments`.

If dependencies are not set, the view will only rebuild when you manually trigger it.

## Testing a View

Before saving, you can test your definition using the **Test Run** button in the admin interface. This executes the template without saving the result, so you can verify the output is correct.

The test results panel shows the JSON output of your definition. If there is an error in your Twig template, the error message will be displayed instead.

## Rebuilding a View

Views rebuild automatically through the [Job Queue](/admin/utils/jobqueue) when dependent collections change. You can also manually rebuild a view by clicking the **Rebuild View** button in the admin interface.

**Important**: The Job Queue must be running for automatic rebuilds to work. If your views are not updating after collection changes, check that the job queue is processing.

## Using Data Views in Templates

### Fetching View Data

```twig
{# Get the pre-computed data for a view #}
{% set stats = cms.view.get('dashboard-stats') %}

{# Access properties from the data #}
{{ stats.blog.total }} blog posts
{{ stats.products.featured }} featured products
```

### Listing All Views

```twig
{# List all available data views #}
{% for view in cms.view.list() %}
    {{ view.name }}
{% endfor %}
```

### Checking Access

```twig
{# Check if the current user can access data views #}
{% if cms.canAccessDataViews() %}
    {% set data = cms.view.get('my-view') %}
{% endif %}
```

## Error Handling

If a view fails to build, Total CMS keeps the previous data intact so your templates continue to work. The error is logged and displayed in the admin interface with a red indicator next to the view name.

Common errors include:
- **Syntax errors** in the Twig definition
- **Missing collections** referenced in the definition
- **Invalid JSON** output (the `data` variable must be serializable to JSON)

## Access Control

Data Views require the **Pro** edition or higher. Access is controlled through [Access Groups](/admin/docs/auth/access-groups) using the `dataviews` permission. Users without this permission cannot view or manage data views.
