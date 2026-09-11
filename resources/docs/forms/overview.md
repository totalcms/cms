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

## What a public form needs

`cms.form.*` renders the form's markup on the server, but validation, file uploads, the save request and the post-save actions come from Total CMS's form script — and today that script ships inside the **admin** bundle. A form on a public page (a signup form, a contact form, a member profile) therefore needs the admin asset helpers in its layout, not just the frontend ones:

```twig
<head>
    {{ cms.assetsHead() }}        {# core frontend assets #}
    {{ cms.adminAssetsHead() }}   {# form styles, icons — no dashboard reset #}
    …
</head>
<body>
    …
    {{ cms.form.builder('members', {register: true}) }}
    …
    {{ cms.assetsBody() }}
    {{ cms.adminAssetsBody() }}   {# the form script, plus the globals it reads #}
</body>
```

`adminAssetsHead()` deliberately leaves out the dashboard's global reset, so it does not restyle the rest of your page; `adminAssetsBody()` also defines the translation catalog and settings the script reads. The CSRF token travels in the hidden field every form carries, so no `<meta>` tag is needed on a public page.

Be aware of the weight: the admin bundle is large (the script alone is around 580 KB compressed) because it carries every field editor the dashboard can show. For a page whose only interactive element is a short form that is a lot, and a dedicated, much smaller forms bundle is planned for a future release. Until then, the alternatives are the [zero-JavaScript form pattern](docs/twig/htmx) built on the API, or a hand-written form posting to the same endpoints.

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
