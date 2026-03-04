---
title: "Twig Edition Reference"
description: "Reference for the cms.edition namespace providing edition detection, feature gating, and license level information."
---

# cms.edition

The edition adapter provides methods to check the current license edition and gate features accordingly. Total CMS has three edition levels: Lite (1), Standard (2), and Pro (3).

## Feature Gating

### can()

Check if a specific feature is available for the current edition.

```twig
{% if cms.edition.can('custom-schemas') %}
    <a href="/admin/schemas/new">Create Custom Schema</a>
{% endif %}

{% if cms.edition.can('data-views') %}
    <a href="/admin/dataviews">Data Views</a>
{% endif %}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `featureName` | string | Feature identifier to check |

### getAllowedFeatures()

Get an array of all feature identifiers allowed for the current edition.

```twig
{% set features = cms.edition.getAllowedFeatures() %}
<ul>
    {% for feature in features %}
        <li>{{ feature }}</li>
    {% endfor %}
</ul>
```

## Edition Detection

### getCurrent()

Get the name of the current edition (e.g., `"lite"`, `"standard"`, `"pro"`).

```twig
<p>Edition: {{ cms.edition.getCurrent() }}</p>
```

### getLevel()

Get the numeric level of the current edition.

```twig
{% set level = cms.edition.getLevel() %}
{# 1 = Lite, 2 = Standard, 3 = Pro #}
```

### getIsStandard()

Check if the current edition is Standard or higher.

```twig
{% if cms.edition.getIsStandard() %}
    {# Standard+ features #}
{% endif %}
```

### getIsPro()

Check if the current edition is Pro.

```twig
{% if cms.edition.getIsPro() %}
    {# Pro-only features #}
{% endif %}
```

### getIsSimulating()

Check if edition simulation is active (for testing edition-restricted features during development).

```twig
{% if cms.edition.getIsSimulating() %}
    <div class="dev-notice">Simulating {{ cms.edition.getCurrent() }} edition</div>
{% endif %}
```

### getInfo()

Get edition information as an array for display purposes.

```twig
{% set info = cms.edition.getInfo() %}
<p>Running {{ info.effectiveEdition }} edition</p>
```
