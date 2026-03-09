---
title: "Schema Validation"
description: "Validate Total CMS schema data with JSON Schema keywords for strings, numbers, arrays, enums, unique constraints, and regex patterns."
---

# Schema Validation

This document covers JSON Schema validation keywords that can be used in the **Extra Schema Definitions** field when setting up properties in a schema.

## Overview

Total CMS uses JSON Schema (Draft 2020-12) for data validation. In addition to the basic schema properties (type, field, label, etc.), you can add validation constraints to ensure data quality and consistency.

**Where to add these:** In the Schema admin interface, when editing a property, use the **Extra Schema Definitions** field to add validation keywords as JSON.

## Required Fields

By default, required string and array fields are automatically validated:

- **Strings**: Cannot be empty (`""`)
- **Arrays** (list, gallery, deck): Cannot be empty (`[]`)
- **Files/Images**: Validated on the frontend (must have a file uploaded)

You can override this behavior by explicitly setting `minLength` or `minItems`.

## String Validation

### minLength / maxLength

Enforce minimum and maximum string length.

```json
{
	"minLength": 3,
	"maxLength": 100
}
```

**Example - Username field:**
```json
{
	"username": {
		"type": "string",
		"field": "text",
		"label": "Username",
		"minLength": 3,
		"maxLength": 20
	}
}
```

**Use cases:**
- Usernames (3-20 characters)
- Product names (minimum 5 characters)
- Short descriptions (maximum 200 characters)
- Passwords (minimum 8 characters)

### pattern

Validate strings against a regular expression.

```json
{
	"pattern": "^[a-zA-Z0-9_-]+$"
}
```

**Example - Slug field:**
```json
{
	"slug": {
		"type": "string",
		"field": "text",
		"label": "URL Slug",
		"pattern": "^[a-z0-9-]+$"
	}
}
```

**Common patterns:**
- **Alphanumeric**: `^[a-zA-Z0-9]+$`
- **URL-safe slug**: `^[a-z0-9-]+$`
- **Hex color**: `^#[0-9A-Fa-f]{6}$`
- **Phone (US)**: `^\\d{3}-\\d{3}-\\d{4}$`

### format

Use built-in format validators.

```json
{
	"format": "email"
}
```

**Available formats:**
- `email` - Email address
- `uri` / `url` - URL
- `date` - Date (YYYY-MM-DD)
- `time` - Time (HH:MM:SS)
- `date-time` - ISO 8601 date-time
- `ipv4` / `ipv6` - IP address

**Example:**
```json
{
	"website": {
		"type": "string",
		"field": "url",
		"label": "Website",
		"format": "uri"
	}
}
```

## Number Validation

### minimum / maximum

Enforce minimum and maximum numeric values.

```json
{
	"minimum": 0,
	"maximum": 100
}
```

**Example - Percentage field:**
```json
{
	"discount": {
		"type": "number",
		"field": "number",
		"label": "Discount %",
		"minimum": 0,
		"maximum": 100
	}
}
```

### exclusiveMinimum / exclusiveMaximum

Like minimum/maximum, but excludes the boundary value.

```json
{
	"exclusiveMinimum": 0,
	"exclusiveMaximum": 100
}
```

**Example - Temperature (must be above 0, not equal to 0):**
```json
{
	"temperature": {
		"type": "number",
		"field": "number",
		"label": "Temperature (°C)",
		"exclusiveMinimum": 0
	}
}
```

### multipleOf

Ensure number is a multiple of a specified value.

```json
{
	"multipleOf": 5
}
```

**Example - Quantity (in packs of 5):**
```json
{
	"quantity": {
		"type": "integer",
		"field": "number",
		"label": "Quantity",
		"multipleOf": 5,
		"minimum": 5
	}
}
```

## Array Validation

### minItems / maxItems

Enforce minimum and maximum array length.

```json
{
	"minItems": 1,
	"maxItems": 10
}
```

**Example - Tags field:**
```json
{
	"tags": {
		"$ref": "https://www.totalcms.co/schemas/properties/list.json",
		"label": "Tags",
		"minItems": 1,
		"maxItems": 5
	}
}
```

**Use cases:**
- Tags (1-5 items)
- Gallery (minimum 3 images)
- Options (maximum 10 choices)
- Team members (minimum 2 members)

### uniqueItems

Ensure all items in an array are unique.

```json
{
	"uniqueItems": true
}
```

**Example - Category tags:**
```json
{
	"categories": {
		"$ref": "https://www.totalcms.co/schemas/properties/list.json",
		"label": "Categories",
		"uniqueItems": true
	}
}
```

**Note:** The `list` property type already has `uniqueItems: true` by default.

## Enum (Allowed Values)

Restrict values to a specific set of allowed options.

```json
{
	"enum": ["draft", "published", "archived"]
}
```

**Example - Status field:**
```json
{
	"status": {
		"type": "string",
		"field": "select",
		"label": "Status",
		"enum": ["draft", "published", "archived"],
		"options": [
			{"value": "draft", "label": "Draft"},
			{"value": "published", "label": "Published"},
			{"value": "archived", "label": "Archived"}
		]
	}
}
```

**Use cases:**
- Predefined statuses
- Size options (S, M, L, XL)
- Priority levels (low, medium, high)
- Content types

## Combining Validations

You can combine multiple validation keywords for comprehensive data validation.

**Example - Product SKU:**
```json
{
	"sku": {
		"type": "string",
		"field": "text",
		"label": "Product SKU",
		"minLength": 8,
		"maxLength": 12,
		"pattern": "^[A-Z]{3}-[0-9]{4,8}$"
	}
}
```

**Example - Price field:**
```json
{
	"price": {
		"type": "number",
		"field": "number",
		"label": "Price (USD)",
		"minimum": 0.01,
		"maximum": 999999.99,
		"multipleOf": 0.01
	}
}
```

**Example - Feature list:**
```json
{
	"features": {
		"$ref": "https://www.totalcms.co/schemas/properties/list.json",
		"label": "Product Features",
		"minItems": 3,
		"maxItems": 10,
		"uniqueItems": true
	}
}
```

## Unique Values

### unique

Ensure that a property's value is unique across all objects in the collection.

```json
{
	"unique": true
}
```

**Example - Email field:**
```json
{
	"email": {
		"$ref": "https://www.totalcms.co/schemas/properties/email.json",
		"label": "Email Address",
		"unique": true
	}
}
```

**Example - Username field:**
```json
{
	"username": {
		"type": "string",
		"field": "text",
		"label": "Username",
		"unique": true,
		"minLength": 3,
		"maxLength": 20
	}
}
```

**Important requirements:**
- **Must be indexed**: Unique properties must be included in the schema's `index` array for performance
- **Case-sensitive**: Uniqueness validation is case-sensitive (e.g., "test@example.com" and "TEST@example.com" are considered different)
- **Empty values allowed**: Multiple objects can have empty/null values for a unique field
- **Update behavior**: When updating an object, you can keep the same value, but cannot change to a value used by another object

**Use cases:**
- User emails (prevent duplicate registrations)
- Usernames (ensure unique login identifiers)
- Product SKUs (avoid inventory conflicts)
- Slug fields (ensure unique URLs)

**Error message:**
When a duplicate value is detected, users will see an error message like:
```
Email must be unique. The value 'john@example.com' already exists in another object.
```

**Schema setup example with index:**
```json
{
	"id": "user",
	"type": "object",
	"properties": {
		"id": {
			"type": "string",
			"label": "ID",
			"field": "input"
		},
		"email": {
			"$ref": "https://www.totalcms.co/schemas/properties/email.json",
			"label": "Email",
			"unique": true
		},
		"username": {
			"type": "string",
			"label": "Username",
			"field": "text",
			"unique": true
		}
	},
	"required": ["id", "email", "username"],
	"index": ["id", "email", "username"]
}
```

**Note:** If you mark a property as unique but don't include it in the index, you'll receive a helpful error message prompting you to add it to the index.

## Disabling Automatic Required Validation

If you want to allow empty values for a required field, explicitly set:

**Allow empty strings (for required fields):**
```json
{
	"minLength": 0
}
```

**Allow empty arrays (for required fields):**
```json
{
	"minItems": 0
}
```

**Example - Optional notes on a required field:**
```json
{
	"notes": {
		"type": "string",
		"field": "textarea",
		"label": "Additional Notes",
		"minLength": 0,
		"maxLength": 500
	}
}
```

## Error Messages

When validation fails, Total CMS will display clear error messages to users:

- **minLength/maxLength**: "String must be at least X characters" or "String exceeds maximum length of X"
- **minimum/maximum**: "Value must be at least X" or "Value must not exceed X"
- **pattern**: "Value does not match required format"
- **enum**: "Value must be one of: X, Y, Z"
- **minItems/maxItems**: "Array must contain at least X items" or "Array exceeds maximum of X items"

## Best Practices

1. **Be specific**: Use validation to enforce your data requirements clearly
2. **Provide context**: Use `help` text to explain validation requirements to users
3. **Balance strictness**: Don't over-validate - allow reasonable flexibility
4. **Test thoroughly**: Verify validation works as expected in the admin interface
5. **Consider UX**: Validation should help users, not frustrate them

## Common Validation Patterns

### Email with length limits
```json
{
	"email": {
		"$ref": "https://www.totalcms.co/schemas/properties/email.json",
		"label": "Email Address",
		"maxLength": 255
	}
}
```

### Phone number (US format)
```json
{
	"phone": {
		"$ref": "https://www.totalcms.co/schemas/properties/phone.json",
		"label": "Phone Number",
		"pattern": "^\\d{3}-\\d{3}-\\d{4}$"
	}
}
```

### Postal code (US ZIP)
```json
{
	"zipcode": {
		"type": "string",
		"field": "text",
		"label": "ZIP Code",
		"pattern": "^\\d{5}(-\\d{4})?$"
	}
}
```

### Percentage (0-100)
```json
{
	"completion": {
		"type": "integer",
		"field": "range",
		"label": "Completion %",
		"minimum": 0,
		"maximum": 100
	}
}
```

### Required gallery (minimum 3 images)
```json
{
	"gallery": {
		"$ref": "https://www.totalcms.co/schemas/properties/gallery.json",
		"label": "Product Images",
		"minItems": 3,
		"maxItems": 20
	}
}
```

### Unique username with validation
```json
{
	"username": {
		"type": "string",
		"field": "text",
		"label": "Username",
		"unique": true,
		"minLength": 3,
		"maxLength": 20,
		"pattern": "^[a-z0-9_-]+$"
	}
}
```

### Unique email address
```json
{
	"email": {
		"$ref": "https://www.totalcms.co/schemas/properties/email.json",
		"label": "Email Address",
		"unique": true,
		"maxLength": 255
	}
}
```

## Reference

For complete JSON Schema documentation, see:
- [JSON Schema Validation Specification](https://json-schema.org/draft/2020-12/json-schema-validation.html)
- [Understanding JSON Schema](https://json-schema.org/understanding-json-schema/)
