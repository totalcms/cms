---
title: "Forms Overview"
description: "Introduction to the Total CMS form system, accessing form methods, default field arguments, and premade collection forms."
---

# Forms Overview

Total CMS provides a comprehensive form building system accessible through the `cms.form` object in Twig templates. All form methods are available through the TotalFormFactory class.

## Accessing Form Methods

All form functionality in Total CMS is accessed through the `cms.form` object:

```twig
{# Access form methods through cms.form #}
{{ cms.form.blog() }}
{{ cms.form.text('my-text-id') }}
{{ cms.form.builder('mycollection').build() }}
```

**Note:** The old method of importing form macros (`{% import "totalform.twig" as form %}`) is deprecated. Always use `cms.form` for accessing form functionality.

## Default Field Arguments

```
field       = type of the field data from Total CMS: text, number, date, etc
type        = type of the input
class       = classes added to the field
value       = value of the field
label       = label of the field
default     = default value of the field if object is not set or value is empty (date fields support natural language)
placeholder = placeholder of the field
help        = help text of the field
icon        = show icon
required    = required field
disabled    = disable field
readonly	= readonly
min         = minimum value
max         = maximum value
step        = step value
pattern     = pattern for validation
autogen     = template string to autogenerate a value (in ID)
settings    = settings array added to form-field data-settings attribute
minlength   = minimum length of the field
```

```twig
{# Example of using field settings #}
{{ cms.form.text('my-text-id', {}, {
	class       : "custom-class",
	value       : "Set Value",
	label       : "Text Label",
	default     : "Default Value",
	placeholder : "Placeholder",
	help        : "Help Text",
	icon        : true,
	required    : true,
	readonly    : true,
	disabled    : true,
	pattern     : "\S+",
	minlength   : "10",
}) }}
```

## Premade Collection Forms

Total CMS provides ready-to-use forms for standard collection types:

```twig
{# Blog form with all fields #}
{{ cms.form.blog() }}

{# Single field forms #}
{{ cms.form.checkbox(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.color(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.date(id, formSettings = {}, fieldSettings = {}) }}  {# Supports natural language defaults #}
{{ cms.form.datetime(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.email(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.image(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.number(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.range(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.select(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.styledtext(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.svg(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.text(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.textarea(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.toggle(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.url(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.file(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.depot(id, formSettings = {}, fieldSettings = {}) }}
{{ cms.form.gallery(id, formSettings = {}, fieldSettings = {}) }}

{# Feed form #}
{{ cms.form.feed() }}
```
