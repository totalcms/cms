---
title: "Select & List Options"
description: "Configure options for select and list fields including static options, grouped options, dynamic options, relational options, and property options."
---

# Select & List Options

## Simple List

```twig
['Option 1', 'Option 2', 'Option 3']
```

## Options with Values

```twig
[
    {value: '1', label: 'Option 1'},
    {value: '2', label: 'Option 2'},
    {value: '3', label: 'Option 3'}
]
```

## Grouped Options

```twig
{
    'Group 1': ['Option 1', 'Option 2'],
    'Group 2': ['Option 3', 'Option 4']
}
```

## Grouped Options with Values

```twig
{
    'Group 1': [
        {value: '1', label: 'Option 1'},
        {value: '2', label: 'Option 2'}
    ],
    'Group 2': [
        {value: '3', label: 'Option 3'},
        {value: '4', label: 'Option 4'}
    ]
}
```

## Dynamic Options

### Property Options

Populate options from all unique values of a property in the current collection:

```json
{
    "propertyOptions": true
}
```

### Relational Options

Populate options from another collection:

```json
{
    "relationalOptions": {
        "collection": "categories",
        "label": "title",
        "value": "id"
    }
}
```

### Combined Example

```json
"settings": {
    "propertyOptions": true,
    "relationalOptions": {
        "collection": "mycollection",
        "label": "name",
        "value": "id"
    }
}
```

### Sorting Options

Sort options alphabetically in select/list fields:

```json
{
    "sortOptions": true
}
```

## Using Options in Twig

### Inline Options

```twig
{{ cms.form.select("myselect", {}, {
    options: {
        "1": "One",
        "2": "Two",
        "3": "Three"
    }
}) }}
```

### Variable Options

```twig
{% set options = [
    {value: "dog",     label: "Dog"},
    {value: "cat",     label: "Cat"},
    {value: "hamster", label: "Hamster"},
    {value: "parrot",  label: "Parrot"},
    {value: "spider",  label: "Spider"},
    {value: "goldfish", label: "Goldfish"}
] %}

{% set form = cms.form.builder('pets') %}
{{ form.field('pet', {
    field: 'select',
    options: options
}) }}
```

### Relational Options in Twig

```twig
{{ cms.form.select("category", {}, {
    settings: {
        relationalOptions: {
            collection: "categories",
            label: "title"
        }
    }
}) }}
```
