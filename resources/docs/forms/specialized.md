---
title: "Specialized Forms"
description: "Schema forms, collection forms, import forms, job queue management, and factory forms."
---

# Specialized Forms

## Schema Forms

```twig
{# Create/edit schema forms #}
{{ cms.form.schema({
    id: 'my-schema-id'  # Optional: for editing existing schema
}) }}
```

## Collection Forms

```twig
{# Create/edit collection forms #}
{{ cms.form.collection({
    id: 'my-collection-id'  # Optional: for editing existing collection
}) }}
```

## Import Forms

```twig
{# Import data into a collection #}
{{ cms.form.importCollection('blog') }}

{# Import schema #}
{{ cms.form.importSchema() }}
```

## Job Queue Management

```twig
{# Display job queue statistics #}
{{ cms.form.jobqueueStats() }}

{# Job queue by status #}
{{ cms.form.jobqueueByStatus({
    header: 'Queue Status'
}) }}

{# Job queue by type #}
{{ cms.form.jobqueueByType({
    header: 'Queue Types'
}) }}

{# Clear queue form #}
{{ cms.form.clearqueue() }}
```

## Factory Forms

```twig
{# Factory form for bulk object creation #}
{{ cms.form.factory('blog') }}
```
