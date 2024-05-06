# Docs for form twig macros


## Importing Form Macros

```
{% import "totalform.twig" as form %}
```

## Default Field Arguments

```
field       = type of the field data from Total CMS: text, number, date, etc
type        = type of the input
class       = classes added to the field
value       = value of the field
label       = label of the field
default     = default value of the field if object is not set or value is empty
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
options     = options array added to form-field data-options attribute
minlength   = minimum length of the field
```

```
{{ form.text(property, {
	class       : "class",
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

```
{{ form.blogForm() }}
{{ form.checkboxForm(id, args = {}, collection = "toggle") }}
{{ form.colorForm(id, args = {}, collection = "color") }}
{{ form.dateForm(id, args = {}, collection = "date") }}
{{ form.datetimeForm(id, args = {}, collection = "date") }}
{{ form.emailForm(id, args = {}, collection = "email") }}
{{ form.imageForm(id, args = {}, collection = "image") }}
{{ form.numberForm(id, args = {}, collection = "number") }}
{{ form.rangesliderForm(id, args = {}, collection = "number") }}
{{ form.selectForm(id, options = [], args = {}, collection = "text") }}
{{ form.styledtextForm(id, args = {}, collection = "styledtext") }}
{{ form.svgForm(id, args = {}, collection = "svg") }}
{{ form.textForm(id, args = {}, collection = "text") }}
{{ form.textareaForm(id, args = {}, collection = "text",) }}
{{ form.toggleForm(id, args = {}, collection = "toggle") }}
{{ form.urlForm(id, args = {}, collection = "url") }}
```

## Custom Forms

### Form Wrappers

```
{{ form.start(collection, args = {}) }}
{{ form.end(button = false) }}
```

### Buttons

```
{{ form.saveButton(label = "Save") }}
{{ form.deleteButton(label = "Delete") }}
```

### Fields

```
{{ form.checkbox(property, args = {}) }}
{{ form.color(property, args = {}) }}
{{ form.date(property, args = {}) }}
{{ form.datetime(property, args = {}) }}
{{ form.deck(property, value) }}
{{ form.depot(property, value) }}
{{ form.file(property, value) }}
{{ form.gallery(property, value) }}
{{ form.hidden(property, args = {}) }}
{{ form.id(property = "id", args = {}) }}
{{ form.image(property, args = {}) }}
{{ form.input(property, args = {}) }}
{{ form.list(property, options, args = {}) }}
{{ form.markdown(property, args = {}) }}
{{ form.multiselect(property, options = [], args = {}) }}
{{ form.number(property, args = {}) }}
{{ form.password(property, args = {}) }}
{{ form.radio(property, options = [], args = {}) }}
{{ form.rangeslider(property, args = {}) }}
{{ form.select(property, options = [], args = {}) }}
{{ form.styledtext(property, args = {}) }}
{{ form.svg(property, args = {}) }}
{{ form.textarea(property, args = {}) }}
{{ form.time(property, args = {}) }}
{{ form.toggle(property, args = {}) }}
```

```
{{ form.text(property, {
	class       : "string",
	value       : "string",
	default     : "string",
	label       : "string",
	placeholder : "string",
	help        : "string",
	pattern     : "string",
	icon        : true,
	required    : true,
	readonly    : true,
	disabled    : true,
	minlength   : 10,
}) }}
{{ form.phone(property, args = {}) }}
{{ form.email(property, args = {}) }}
{{ form.url(property, args = {}) }}
```


## Form Patterns

```
paterns.alphaNumeric
paterns.notBlank
paterns.passwordUpperLowerNumber
paterns.date
paterns.time
paterns.dateTime
paterns.integer
paterns.decimal
paterns.hex
paterns.ipv4
paterns.ipv6
paterns.domain
paterns.slug
paterns.uuid
paterns.macAddress
paterns.creditCard
paterns.isbn
paterns.currency
paterns.latitudeLongitude
paterns.html
patterns.postCode.australia
patterns.postCode.austria
patterns.postCode.belgium
patterns.postCode.brazil
patterns.postCode.canada
patterns.postCode.germany
patterns.postCode.hungary
patterns.postCode.italy
patterns.postCode.japan
patterns.postCode.luxembourg
patterns.postCode.netherlands
patterns.postCode.poland
patterns.postCode.spain
patterns.postCode.sweden
patterns.postCode.uk
patterns.postCode.usa
patterns.phone.usa
patterns.phone.uk
patterns.phone.france
patterns.phone.international
patterns.passwordMinLength(int minLength = 8)
```