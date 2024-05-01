# Docs for form twig macros


## Importing Form Macros

{% import "totalform.twig" as form %}

## Default Field Arguments

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
min         = minimum value
max         = maximum value
step        = step value
pattern     = pattern for validation
autogen     = template string to autogenerate a value (in ID)
options     = options array added to form-field data-options attribute
minlength   = minimum length of the field


## Premade Collection Forms

{{ totalcms.blogForm() }}
{{ totalcms.checkboxForm(id, args = {}, collection = "toggle") }}
{{ totalcms.colorForm(id, args = {}, collection = "color") }}
{{ totalcms.dateForm(id, args = {}, collection = "date") }}
{{ totalcms.datetimeForm(id, args = {}, collection = "date") }}
{{ totalcms.emailForm(id, args = {}, collection = "email") }}
{{ totalcms.imageForm(id, args = {}, collection = "image") }}
{{ totalcms.numberForm(id, args = {}, collection = "number") }}
{{ totalcms.rangesliderForm(id, args = {}, collection = "number") }}
{{ totalcms.styledtextForm(id, args = {}, collection = "styledtext") }}
{{ totalcms.svgForm(id, args = {}, collection = "svg") }}
{{ totalcms.textForm(id, args = {}, collection = "text") }}
{{ totalcms.textareaForm(id, args = {}, collection = "text",) }}
{{ totalcms.toggleForm(id, args = {}, collection = "toggle") }}
{{ totalcms.urlForm(id, args = {}, collection = "url") }}

## Custom Forms

### Form Wrappers

{{ totalcms.start(collection, args = {}) }}
{{ totalcms.end(button = false) }}

### Buttons

{{ totalcms.saveButton(label = "Save") }}
{{ totalcms.deleteButton(label = "Delete") }}

### Fields

{{ totalcms.checkbox(property, args = {}) }}
{{ totalcms.color(property, args = {}) }}
{{ totalcms.date(property, args = {}) }}
{{ totalcms.datetime(property, args = {}) }}
{{ totalcms.deck(property, value) }}
{{ totalcms.depot(property, value) }}
{{ totalcms.email(property, args = {}) }}
{{ totalcms.file(property, value) }}
{{ totalcms.gallery(property, value) }}
{{ totalcms.hidden(property, args = {}) }}
{{ totalcms.id(property = "id", args = {}) }}
{{ totalcms.image(property, args = {}) }}
{{ totalcms.input(property, args = {}) }}
{{ totalcms.list(property, options, args = {}) }}
{{ totalcms.markdown(property, args = {}) }}
{{ totalcms.multiselect(property, options = [], args = {}) }}
{{ totalcms.number(property, args = {}) }}
{{ totalcms.password(property, args = {}) }}
{{ totalcms.phone(property, args = {}) }}
{{ totalcms.radio(property, options = [], args = {}) }}
{{ totalcms.rangeslider(property, args = {}) }}
{{ totalcms.select(property, options = [], args = {}) }}
{{ totalcms.styledtext(property, args = {}) }}
{{ totalcms.svg(property, args = {}) }}
{{ totalcms.text(property, args = {}) }}
{{ totalcms.textarea(property, args = {}) }}
{{ totalcms.time(property, args = {}) }}
{{ totalcms.toggle(property, args = {}) }}
{{ totalcms.url(property, args = {}) }}


## Form Patterns

paterns.alphaNumeric
paterns.notBlank
paterns.passwordUpperLowerNumber
paterns.email
paterns.url
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