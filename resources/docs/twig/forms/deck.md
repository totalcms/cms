---
title: "Deck Forms"
description: "Create and edit individual items within deck properties using deck forms and the deck form builder."
---

# Deck Forms

Deck forms allow you to create and edit individual items within a deck property. Deck properties contain multiple sub-items, each following a schema referenced by `deckref`.

## Understanding Deck Properties

A deck property is a special property type that contains multiple sub-items. Each item has its own ID and follows a schema defined by the deck's `deckref`:

```json
{
	"features": {
		"type": "deck",
		"label": "Product Features",
		"deckref": "https://www.totalcms.co/schemas/custom/feature-item.json",
		"settings": {
			"help": "Add individual feature items for this product"
		}
	}
}
```

The referenced schema (`feature-item.json`) defines the structure of each deck item:

```json
{
	"$id": "https://www.totalcms.co/schemas/custom/feature-item.json",
	"title": "Feature Item",
	"type": "object",
	"required": ["id", "title"],
	"properties": {
		"id": {
			"type": "string",
			"label": "Feature ID"
		},
		"title": {
			"type": "string",
			"label": "Feature Title"
		},
		"description": {
			"type": "string",
			"label": "Description",
			"field": "textarea"
		}
	}
}
```

## Auto-Built Deck Forms

The simplest way to create a deck item form:

```twig
{# Create new deck item #}
{{ cms.form.deck('products', productId, 'features') }}

{# Edit existing deck item #}
{{ cms.form.deck('products', productId, 'features', {itemId: 'feature-1'}) }}
```

**Parameters:**
- **collection** (string) - The parent collection name (e.g., 'products')
- **id** (string) - The parent object ID (e.g., product ID)
- **property** (string) - The deck property name (e.g., 'features')
- **options** (object) - Optional configuration (see below)

## Deck Form Builder

For custom layouts and advanced control:

```twig
{# Create a deck form builder instance #}
{% set form = cms.form.deckBuilder('products', productId, 'features') %}

{# Build custom layout #}
{{ form.build(
	form.field('id') ~
	form.field('title') ~
	form.field('description') ~
	form.field('icon')
) }}
```

## Deck Form Options

All standard form options are supported, plus deck-specific options:

```twig
{{ cms.form.deck('products', productId, 'features', {
	itemId: 'feature-1',          # Deck item ID (empty for new items)
	save: 'Save Feature',          # Custom save button text
	delete: 'Delete Feature',      # Custom delete button text
	class: 'feature-form',         # Additional CSS classes
	helpOnHover: true,             # Show help on hover
	newActions: [                  # Actions after creating new item
		{
			action: 'redirect',
			link: '/products/{parentId}'
		}
	],
	editActions: [                 # Actions after editing item
		{
			action: 'refresh'
		}
	]
}) }}
```

## How Deck Forms Work

1. **Auto-detection**: The form automatically detects the `deckref` from the property's schema
2. **Schema Loading**: Loads the deck schema (e.g., `feature-item.json`) for field definitions
3. **Data Loading**: If `itemId` is provided, loads existing deck item data
4. **API Routes**: Automatically sets correct routes:
   - **New items**: `POST /collections/{collection}/{id}/{property}/deck`
   - **Edit items**: `PUT /collections/{collection}/{id}/{property}/deck/{itemId}`

## Query Parameter Support

Deck forms support automatic ID detection from URL parameters:

```twig
{# Form will read ?id=product-123&itemId=feature-1 from URL #}
{{ cms.form.deck('products', '', 'features') }}
```

This is useful for admin interfaces where IDs come from the URL.

## Examples

### Product Features Manager

```twig
{# products/edit.twig #}
{% set product = cms.collection.object('products', productId) %}

<h2>{{ product.name }} - Manage Features</h2>

{# List existing features #}
{% if product.features %}
	<ul>
	{% for featureId, feature in product.features %}
		<li>
			{{ feature.title }}
			<a href="/admin/products/{{ productId }}/features/{{ featureId }}">Edit</a>
		</li>
	{% endfor %}
	</ul>
{% endif %}

{# Add new feature button #}
<a href="/admin/products/{{ productId }}/features/new">Add New Feature</a>
```

```twig
{# products/feature-edit.twig #}
<h2>Edit Feature</h2>

{# Auto-built form with custom actions #}
{{ cms.form.deck('products', productId, 'features', {
	itemId: featureId,
	save: 'Save Feature',
	delete: 'Delete Feature',
	editActions: [
		{
			action: 'redirect',
			link: '/admin/products/' ~ productId
		}
	],
	deleteActions: [
		{
			action: 'redirect',
			link: '/admin/products/' ~ productId
		}
	]
}) }}
```

### Custom Layout with Form Builder

```twig
{# Custom two-column layout for deck items #}
{% set form = cms.form.deckBuilder('products', productId, 'features', {
	itemId: featureId,
	save: 'Save Feature'
}) %}

{# Build custom layout #}
{% set col1 = form.field('id') %}
{% set col1 = col1 ~ form.field('title') %}
{% set col1 = col1 ~ form.field('subtitle') %}

{% set col2 = form.field('icon', {field: 'image'}) %}
{% set col2 = col2 ~ form.field('description', {field: 'styledtext'}) %}
{% set col2 = col2 ~ form.field('priority', {field: 'number'}) %}

{% set layout = form.layout2Columns(col1, col2) %}
{{ form.build(layout) }}
```

### Portfolio Project Items

```twig
{# Portfolio collection with "projectItems" deck property #}
{% set form = cms.form.deckBuilder('portfolio', portfolioId, 'projectItems', {
	itemId: itemId,
	newActions: [
		{
			action: 'message',
			text: 'Project item added successfully!'
		},
		{
			action: 'redirect',
			link: '/admin/portfolio/' ~ portfolioId
		}
	]
}) %}

{{ form.build(
	form.field('id') ~
	form.field('title') ~
	form.field('image', {
		settings: {
			rules: {
				width: {min: 800, max: 2400},
				aspectratio: '16:9'
			}
		}
	}) ~
	form.field('description') ~
	form.field('tags', {field: 'list'})
) }}
```

## Best Practices

1. **Always provide collection, parent ID, and property name** for deck forms
2. **Use `itemId` in options** to edit existing items
3. **Set appropriate actions** to redirect users after save/delete
4. **Leverage form builder** for complex custom layouts
5. **Use query parameters** for admin interfaces with URL-based routing

## Common Use Cases

- **Product Features** - Individual features for products
- **FAQ Items** - Question/answer pairs for FAQ pages
- **Team Members** - Individual team member profiles
- **Portfolio Items** - Project details in a portfolio
- **Testimonials** - Individual customer testimonials
- **Timeline Events** - Events in a timeline
- **Pricing Tiers** - Individual pricing plan details
