
title: "Schema Reference"
description: "Complete reference for Total CMS schema settings including inheritance, required fields, indexed properties, form grid layout, and categories."


# Schema Reference

Schemas are the blueprints that define the structure of objects in Total CMS. They determine what properties (fields) are available, which are required, and how data is validated. Custom schemas are stored as JSON files in `/tcms-data/.schemas/`.

## Schema Settings

### id

**Type:** `string` (slug format)
**Required:** Yes

The unique identifier for this schema. Used to reference the schema from collections.

```json
{
    "id": "team-member"
}
```

**Constraints:**
- Must be URL-safe (lowercase, hyphens, no spaces)
- Cannot conflict with built-in schema IDs


### description

**Type:** `string`
**Required:** No

A description of the schema's purpose. Shown in the admin interface.

```json
{
    "description": "Team member profiles with bio, photo, and role."
}
```


### category

**Type:** `string`
**Required:** No

Groups schemas in the admin sidebar. Schemas with the same category appear together under a collapsible heading.

```json
{
    "category": "Content"
}
```



### properties

**Type:** `object`
**Required:** Yes

The property definitions that make up this schema. Each key is a property name, and the value is its definition including type, field, label, and validation rules.

```json
{
    "properties": {
        "title": {
            "type": "string",
            "field": "text",
            "label": "Title"
        },
        "content": {
            "type": "string",
            "field": "styledtext",
            "label": "Content"
        }
    }
}
```

See [Schema Validation](docs/schemas/validation) for validation options and [Field Settings](docs/fields/all-fields) for field configuration.



### required

**Type:** `array<string>`
**Required:** Yes

A list of property names that must be filled in when creating or editing objects.

```json
{
    "required": ["id", "title", "date"]
}
```

Required properties can reference both local properties and inherited properties. When inheritance is used, the `required` arrays from all parent schemas are merged with the child's `required` array (duplicates removed).



### index

**Type:** `array<string>`
**Required:** Yes

A list of property names to include in the collection index. Indexed properties are available when listing objects without loading full object data, enabling efficient filtering and sorting.

```json
{
    "index": ["id", "title", "date", "category"]
}
```

Like `required`, the `index` array can reference inherited properties and is merged with parent schemas during inheritance.



### formgrid

**Type:** `string`
**Required:** No

A text-based grid layout for arranging form fields in the admin interface. Uses CSS Grid syntax with property names as grid area identifiers.

```json
{
    "formgrid": "id id id\ntitle title title\ncontent content content"
}
```

See [Form Grid Layout](docs/schemas/formgrid) for complete documentation.



### inheritFrom

**Type:** `array<string>`
**Required:** No
**Default:** `[]`

A list of schema IDs to inherit properties from. Schema inheritance allows you to share common properties across multiple schemas without duplicating definitions.

```json
{
    "inheritFrom": ["base-content"]
}
```

**How inheritance works:**

- **Child properties take precedence**: If both the child and parent schema define a property with the same name, the child's definition is used.
- **Properties are merged**: Parent properties that don't exist in the child are added to the child schema.
- **Required and index arrays are merged**: The `required` and `index` arrays from all parent schemas are combined with the child's arrays (duplicates removed).
- **Multiple parents are supported**: You can inherit from more than one schema. When multiple parents define the same property, the first parent listed wins.
- **Single-level only**: Inheritance is limited to one level deep. Parent schemas cannot themselves inherit from other schemas — only the direct parent's properties are used.
- **Missing parents are skipped**: If a parent schema ID doesn't exist, it is silently skipped without causing errors.
- **Deletion protection**: A schema cannot be deleted if other schemas inherit from it.

**Example — Base content schema:**

```json
{
    "id": "base-content",
    "properties": {
        "title": {
            "type": "string",
            "field": "text",
            "label": "Title"
        },
        "author": {
            "type": "string",
            "field": "text",
            "label": "Author"
        },
        "date": {
            "$ref": "https://www.totalcms.co/schemas/properties/date.json",
            "label": "Date"
        }
    },
    "required": ["id", "title"],
    "index": ["id", "title", "date"]
}
```

**Example — Child schema inheriting from base:**

```json
{
    "id": "article",
    "inheritFrom": ["base-content"],
    "properties": {
        "content": {
            "type": "string",
            "field": "styledtext",
            "label": "Content"
        },
        "category": {
            "type": "string",
            "field": "select",
            "label": "Category",
            "options": [
                {"label": "News", "value": "news"},
                {"label": "Tutorial", "value": "tutorial"}
            ]
        }
    },
    "required": ["id", "content"],
    "index": ["id", "category"]
}
```

The resolved `article` schema will have properties: `title`, `author`, `date` (from `base-content`), plus `content` and `category` (its own). The `required` array becomes `["id", "title", "content"]` and `index` becomes `["id", "title", "date", "category"]`.

**Accessing inherited properties in Twig:**

```twig
{% set inherited = cms.schema.inheritedProperties('article') %}
{% for prop in inherited %}
    <p>{{ prop.field }} ({{ prop.type }}) — from {{ prop.source }}</p>
{% endfor %}
```

See [cms.schema.inheritedProperties()](docs/twig/schemas#inheritedproperties) for more details.



## Complete Schema Example

```json
{
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "$id": "https://www.totalcms.co/schemas/article.json",
    "id": "article",
    "type": "object",
    "description": "News articles and blog posts with categories and tags.",
    "category": "Content",
    "inheritFrom": ["base-content"],
    "formgrid": "id id date\ntitle title category\ncontent content content",
    "properties": {
        "content": {
            "type": "string",
            "field": "styledtext",
            "label": "Content"
        },
        "category": {
            "type": "string",
            "field": "select",
            "label": "Category",
            "options": [
                {"label": "News", "value": "news"},
                {"label": "Tutorial", "value": "tutorial"}
            ]
        },
        "tags": {
            "$ref": "https://www.totalcms.co/schemas/properties/list.json",
            "label": "Tags",
            "field": "list"
        }
    },
    "required": ["id", "content"],
    "index": ["id", "category"]
}
```



## See Also

- [Schema Validation](docs/schemas/validation) - Validation rules for schema properties
- [Form Grid Layout](docs/schemas/formgrid) - Arranging fields in the admin form
- [Collection Settings](docs/collections/settings) - Collection-level configuration
- [cms.schema](docs/twig/schemas) - Twig functions for accessing schemas
