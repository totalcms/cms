---
title: "Twig Schemas Reference"
description: "Reference for the cms.schema namespace providing schema listing, fetching, category grouping, inheritance, and deck compatibility."
---

# cms.schema

The schema adapter provides access to schema definitions — the blueprints that define object structure for collections.

## Listing Schemas

### list()

Get all accessible schemas (filtered by edition restrictions).

```twig
{% for schema in cms.schema.list() %}
    <p>{{ schema.id }} — {{ schema.name }}</p>
{% endfor %}
```

### reserved()

Get all accessible built-in (reserved) schemas.

```twig
{% for schema in cms.schema.reserved() %}
    <p>{{ schema.id }}</p>
{% endfor %}
```

### custom()

Get all accessible custom schemas (Pro edition only).

```twig
{% if cms.edition.getIsPro() %}
    {% for schema in cms.schema.custom() %}
        <p>{{ schema.id }} — {{ schema.name }}</p>
    {% endfor %}
{% endif %}
```

### byCategory()

Get schemas grouped by their category.

```twig
{% for category, schemas in cms.schema.byCategory() %}
    <h3>{{ category }}</h3>
    <ul>
        {% for schema in schemas %}
            <li>{{ schema.name }}</li>
        {% endfor %}
    </ul>
{% endfor %}
```

## Fetching Schemas

### get()

Get a single schema definition as an array.

```twig
{% set schema = cms.schema.get('blog') %}
<h2>{{ schema.name }}</h2>
<p>{{ schema.description }}</p>

{# Access schema properties #}
{% for prop in schema.properties %}
    <p>{{ prop.name }}: {{ prop.type }}</p>
{% endfor %}
```

### forCollection()

Get the schema assigned to a specific collection.

```twig
{% set schema = cms.schema.forCollection('my-blog') %}
<p>This collection uses the {{ schema.name }} schema</p>
```

## Schema Properties

### inheritedProperties()

Get all inherited properties for a schema, including the source schema each property comes from.

```twig
{% set inherited = cms.schema.inheritedProperties('blog') %}
{% for prop in inherited %}
    <p>{{ prop.field }} ({{ prop.type }}) — inherited from {{ prop.source }}</p>
{% endfor %}
```

**Returns:** Array of objects with keys:
- `source` — schema ID the property is inherited from
- `field` — property field name
- `type` — property type
- `definition` — full property definition array

## Deck Compatibility

### isDeckCompatible()

Check if a schema is compatible with deck usage. Decks require specific property types.

```twig
{% if cms.schema.isDeckCompatible('blog') %}
    <p>This schema can be used in decks</p>
{% endif %}
```

### deckIncompatibleTypes()

Get the list of property types in a schema that are incompatible with deck usage.

```twig
{% set incompatible = cms.schema.deckIncompatibleTypes('blog') %}
{% if incompatible|length > 0 %}
    <p>Incompatible types: {{ incompatible|join(', ') }}</p>
{% endif %}
```
