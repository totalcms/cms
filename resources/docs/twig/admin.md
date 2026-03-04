---
title: "Twig Admin Reference"
description: "Reference for the cms.admin namespace providing dashboard stats, job queue management, URL helpers, and system diagnostics."
---

# cms.admin

The admin adapter provides methods for the admin interface including dashboard statistics, job queue management, URL rewrite rule generation, and system diagnostics.

## Dashboard

### dashboardStats()

Get summary statistics for the admin dashboard.

```twig
{% set stats = cms.admin.dashboardStats() %}
<p>{{ stats.collections }} collections</p>
<p>{{ stats.schemas }} schemas</p>
<p>{{ stats.templates }} templates</p>
<p>{{ stats.totalObjects }} total objects</p>
<p>{{ stats.jobs }} pending jobs</p>
```

**Returns:** `array` with keys `collections`, `schemas`, `templates`, `totalObjects`, `jobs`

### dashboardRecentCollections()

Get the 10 most recently updated accessible collections.

```twig
{% for col in cms.admin.dashboardRecentCollections() %}
    <a href="{{ col.url }}">{{ col.name }} ({{ col.objectCount }} objects)</a>
{% endfor %}
```

### dashboardEmptyCollections()

Get collections with zero objects that may need attention.

```twig
{% set empty = cms.admin.dashboardEmptyCollections() %}
{% if empty|length > 0 %}
    <h3>Empty Collections</h3>
    {% for col in empty %}
        <p>{{ col.name }} has no objects yet</p>
    {% endfor %}
{% endif %}
```

### dashboardSystemStatus()

Get system status information including PHP version, CMS version, cache backends, memory limits, and license info.

```twig
{% set status = cms.admin.dashboardSystemStatus() %}
<p>PHP {{ status.phpVersion }}</p>
<p>CMS {{ status.cmsVersion }}</p>
```

### dashboardRecentObjects()

Get the 10 most recently created or updated objects across all collections.

```twig
{% for obj in cms.admin.dashboardRecentObjects() %}
    <p>{{ obj.id }} in {{ obj.collection }} — {{ obj.updated }}</p>
{% endfor %}
```

## Job Queue

### processJobQueueCommand()

Get the CLI command string for processing the job queue. Useful for displaying setup instructions in the admin.

```twig
<code>{{ cms.admin.processJobQueueCommand() }}</code>
```

### jobQueuePendingInfo()

Get an HTML table showing pending jobs in the queue.

```twig
{{ cms.admin.jobQueuePendingInfo()|raw }}
```

### jobQueueFailedInfo()

Get an HTML table showing failed jobs.

```twig
{{ cms.admin.jobQueueFailedInfo()|raw }}
```

## Development Mode

### devModeStatus()

Get development mode status as an array.

```twig
{% set devMode = cms.admin.devModeStatus() %}
{% if devMode.active %}
    <div class="dev-banner">Development Mode Active</div>
{% endif %}
```

### isDevModeActive()

Check if development mode is currently active.

```twig
{% if cms.admin.isDevModeActive() %}
    <div class="debug-panel">...</div>
{% endif %}
```

## Templates

### templatesByFolder()

Group all templates by their folder path for admin sidebar navigation.

```twig
{% for folder, templates in cms.admin.templatesByFolder() %}
    <h4>{{ folder }}</h4>
    <ul>
        {% for tpl in templates %}
            <li>{{ tpl.name }}</li>
        {% endfor %}
    </ul>
{% endfor %}
```

**Returns:** `array<string, array>` — folder paths as keys, arrays of template info as values

## URL Helpers

### apacheRule()

Generate Apache mod_rewrite rules for pretty URLs.

```twig
{{ cms.admin.apacheRule(cms.currentUrl, 'Blog Posts') }}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `url` | string | The URL pattern to rewrite |
| `collection` | string | Label for the comment (default: `'Collection'`) |

### nginxRule()

Generate Nginx rewrite rules for pretty URLs.

```twig
{{ cms.admin.nginxRule(cms.currentUrl, 'Products') }}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `url` | string | The URL pattern to rewrite |
| `collection` | string | Label for the comment (default: `'Collection'`) |

## Quick Actions

### quickActionButton()

Build an HTMX-powered quick action button for admin operations.

```twig
{{ cms.admin.quickActionButton('Clear Cache', '/api/cache/clear', {
    method: 'post',
    confirm: 'Are you sure?',
    reload: true,
    class: 'btn-danger'
})|raw }}
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `method` | string | `'post'` | HTTP method |
| `confirm` | string | `''` | Confirmation message before executing |
| `reload` | bool | `false` | Reload page after action completes |
| `redirect` | string | `''` | Redirect URL after action completes |
| `class` | string | `''` | Additional CSS classes |

## Edition Restrictions

### inaccessibleCollections()

Get collections that are inaccessible due to the current edition restrictions.

```twig
{% set locked = cms.admin.inaccessibleCollections() %}
{% for col in locked %}
    <p>{{ col.name }} requires a higher edition</p>
{% endfor %}
```

### inaccessibleSchemas()

Get schema IDs that are inaccessible due to the current edition restrictions.

```twig
{% set locked = cms.admin.inaccessibleSchemas() %}
{% for schemaId in locked %}
    <p>{{ schemaId }} schema is not available</p>
{% endfor %}
```

## Diagnostics Sub-Objects

The admin adapter also exposes diagnostic sub-objects:

```twig
{# Server information #}
{{ cms.admin.checker.serverInfo() }}
{{ cms.admin.checker.checkRequiredSoftware() }}
{{ cms.admin.checker.checkOptionalSoftware() }}
{{ cms.admin.checker.getVersion() }}

{# Cache status #}
{{ cms.admin.cacheReporter.getStatus() }}

{# Error logs #}
{{ cms.admin.logAnalyzer.getRecentErrors(10) }}
```
