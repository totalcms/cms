---
title: "Number and Range Fields"
description: "Configure number and range input fields in Total CMS with min, max, and step settings for precise numeric value control."
---

# Number and Range Fields

```json
{
  "min"  : 1,
  "max"  : 10,
  "step" : 0.25
}
```

## Public counters

A number field can be opened to anonymous increments — likes, "was this
helpful", download counts — without opening anything else on the object:

```json
{
	"likes": {
		"type"     : "number",
		"field"    : "number",
		"default"  : 0,
		"settings" : { "publicIncrement" : true }
	}
}
```

Give the property `"type": "integer"` instead when only whole numbers make sense — a count, a sort order, a page size. The number field renders the same either way; the difference is validation, where `integer` rejects `2.5`, and the schema page, which shows the same number icon for both.


With `publicIncrement` set, unauthenticated callers may `POST` to the field's
`/increment` and `/decrement` routes; every other write still needs
authentication or the collection's `publicOperations`. Anonymous counter
writes are limited to 60 per minute per IP. Treat the number as a soft
signal, not a vote: anyone can call it. The [HTMX Recipes](docs/twig/htmx)
page shows the one-line like button.

