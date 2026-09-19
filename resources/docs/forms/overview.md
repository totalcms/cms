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

`cms.form.*` renders the form's markup on the server. Validation, file uploads, the save request and the post-save actions come from Total CMS's form script, which ships as the `forms` core frontend feature: a stylesheet and a small module that `cms.assetsHead()` and `cms.assetsBody()` already emit. A public form needs nothing beyond the two helpers every layout has:

```twig
<head>
    {{ cms.assetsHead() }}        {# core frontend assets, forms.css among them #}
    …
</head>
<body>
    …
    {{ cms.form.builder('members', {register: true}) }}
    …
    {{ cms.assetsBody() }}        {# forms.js, and the globals it reads #}
</body>
```

`forms.js` carries the form runtime and the light field classes a public form is made of — text, textarea, number, select, checkbox, toggle, radio, date, color, password. A heavier field (styled text, image and file uploads, code, lists, decks) loads its own module the first time a form on the page renders it, so a contact form never downloads the editor a blog post needs. The whole feature is under 50 KB compressed; the admin bundle it replaces on public pages is around 300 KB.

The CSRF token travels in the hidden field every form carries, so no `<meta>` tag is needed. `cms.assetsBody()` defines the translation catalog and settings the script reads whenever the feature is on the page. A site with no public forms leaves the pair out with `forms` in `frontendAssets.except` (see [Frontend Assets](docs/site-builder/frontend)); a customer admin page that calls both the frontend and the admin helpers gets one form runtime, since `forms.js` stands down when `admin.js` is present.

Before 3.6 a public form needed `cms.adminAssetsHead()` and `cms.adminAssetsBody()` in its layout. Those calls still work, and a page that keeps them keeps working; they are simply no longer needed for forms.

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
