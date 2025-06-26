# Total CMS Forms Documentation

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
options     = options array added to form-field data-options attribute
minlength   = minimum length of the field
```

```twig
{# Example of using field options #}
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
{{ cms.form.checkbox(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.color(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.date(id, formOptions = {}, fieldOptions = {}) }}  {# Supports natural language defaults #}
{{ cms.form.datetime(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.email(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.image(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.number(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.range(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.select(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.styledtext(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.svg(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.text(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.textarea(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.toggle(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.url(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.file(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.depot(id, formOptions = {}, fieldOptions = {}) }}
{{ cms.form.gallery(id, formOptions = {}, fieldOptions = {}) }}

{# Feed form #}
{{ cms.form.feed() }}
```

## Custom Forms with Form Builder

The form builder provides the most flexibility for creating custom forms:

### Basic Form Builder Usage

```twig
{# Create a form builder instance #}
{% set form = cms.form.builder('mycollection') %}

{# Add fields to the form #}
{% set form = form.addField('title') %}
{% set form = form.addField('content', {field: 'styledtext'}) %}
{% set form = form.addField('date') %}

{# Build and render the form #}
{{ form.build() }}
```

### Advanced Form Builder Example

```twig
{# Create a complex form with custom layout #}
{% set form = cms.form.builder('products', {
    id: 'my-product-id',
    hideID: false,
    save: 'Save Product',
    delete: 'Delete Product'
}) %}

{# Build form content in columns #}
{% set col1 = form.field('id') %}
{% set col1 = col1 ~ form.field('name') %}
{% set col1 = col1 ~ form.field('description', {field: 'styledtext'}) %}
{% set col1 = col1 ~ form.field('price', {field: 'number'}) %}

{% set col2 = form.field('category', {field: 'select'}) %}
{% set col2 = col2 ~ form.field('tags', {field: 'list'}) %}
{% set col2 = col2 ~ form.field('featured', {field: 'toggle'}) %}
{% set col2 = col2 ~ form.field('image') %}

{# Create two-column layout #}
{% set layout = form.layout2Columns(col1, col2) %}

{# Build form with custom layout #}
{{ form.build(layout) }}
```

### Form Buttons

```twig
{# Standalone buttons #}
{{ cms.form.save('Save Changes') }}
{{ cms.form.delete('Remove Item') }}
```

### Simple Forms

For basic form submission without full object management:

```twig
{# Create a simple form that posts to a route #}
{{ cms.form.simple('/api/contact', '<input name="email" type="email" required>', {
    method: 'POST',
    label: 'Send Message',
    refresh: true
}) }}
```

## Form Options

### Blog Form Options

```twig
{{ cms.form.blog({
    collection: 'blog',
    save: 'Save Post',
    delete: 'Delete Post',
    fields: {
        date: true,
        summary: true,
        content: true,
        author: true,
        tags: true,
        featured: true,
        draft: true,
        image: true,
        categories: false,
        extra: false,
        extra2: false,
        media: false,
        genre: false,
        labels: false,
        archived: false,
        gallery: false
    }
}) }}
```


## Form Patterns

Total CMS provides built-in validation patterns that can be used in form fields:

```twig
{# Using patterns in form fields #}
{{ cms.form.text('my-field', {}, {
    pattern: patterns.email,
    help: 'Please enter a valid email address'
}) }}
```

### Available Patterns

```
patterns.alphaNumeric          # Letters and numbers only
patterns.notBlank              # Cannot be empty
patterns.passwordUpperLowerNumber  # Must contain uppercase, lowercase, and number
patterns.date                  # Date format
patterns.time                  # Time format
patterns.dateTime              # Date and time format
patterns.integer               # Whole numbers only
patterns.decimal               # Decimal numbers
patterns.hex                   # Hexadecimal values
patterns.ipv4                  # IPv4 address
patterns.ipv6                  # IPv6 address
patterns.domain                # Domain name
patterns.slug                  # URL-friendly slug
patterns.uuid                  # UUID format
patterns.macAddress            # MAC address
patterns.creditCard            # Credit card number
patterns.isbn                  # ISBN number
patterns.currency              # Currency format
patterns.latitudeLongitude     # Coordinates
patterns.html                  # HTML content

# Post code patterns by country
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

# Phone patterns
patterns.phone.usa
patterns.phone.uk
patterns.phone.france
patterns.phone.international

# Dynamic patterns
patterns.passwordMinLength(8)  # Minimum password length
```

## Field Settings


### Image Validation

```twig
{{ cms.form.image("myimage", {}, {
    settings: {
        rules: {
            size: {min: 0, max: 300},        # File size in KB
            height: {min: 500, max: 1000},   # Height in pixels
            width: {min: 500, max: 1000},    # Width in pixels
            count: {max: 10},                # Max number of images
            orientation: 'landscape',         # 'landscape', 'portrait', or 'square'
            aspectratio: '4:3',              # Aspect ratio
            filetype: ['image/jpeg', 'image/png'],  # Allowed MIME types
            filename: ['image.jpg']          # Specific filename requirements
        }
    }
}) }}
```

### Date Field Natural Language Defaults

Date fields now support natural language default values powered by CakePHP Chronos. This makes it easy to set smart defaults without complex date calculations.

#### Using Natural Language Defaults in Forms

```twig
{# Basic date field with tomorrow as default #}
{{ cms.form.date('event-date', {}, {
    default: 'tomorrow',
    label: 'Event Date'
}) }}

{# Date field with relative default #}
{{ cms.form.date('deadline', {}, {
    default: '+1 week',
    label: 'Project Deadline'
}) }}

{# Using with form builder #}
{% set form = cms.form.builder('tasks') %}
{{ form.field('due_date', {
    field: 'date',
    default: 'next friday',
    label: 'Due Date'
}) }}
```

#### Supported Natural Language Formats

```twig
{# Relative dates #}
default: 'now'              {# Current date/time #}
default: 'today'            {# Today at midnight #}
default: 'tomorrow'         {# Tomorrow #}
default: 'yesterday'        {# Yesterday #}

{# Relative intervals #}
default: '+1 day'           {# 1 day from now #}
default: '+2 weeks'         {# 2 weeks from now #}
default: '+3 months'        {# 3 months from now #}
default: '+1 year'          {# 1 year from now #}
default: '-7 days'          {# 7 days ago #}
default: '-1 month'         {# 1 month ago #}

{# Natural language #}
default: 'next monday'      {# Next Monday #}
default: 'last friday'      {# Last Friday #}
default: 'first day of this month'
default: 'last day of this month'
default: 'first day of next month'
default: 'next saturday 2:00 PM'
```

#### Schema Definition Examples

When defining date fields in schemas, you can use natural language defaults:

```json
{
    "type": "date",
    "label": "Event Date",
    "default": "tomorrow"
}

{
    "type": "date",
    "label": "Deadline",
    "default": "+30 days"
}

{
    "type": "date",
    "label": "Review Date",
    "default": "first day of next month"
}
```

#### Practical Examples

```twig
{# Event creation form with smart defaults #}
{% set form = cms.form.builder('events') %}

{{ form.field('start_date', {
    field: 'date',
    default: 'next saturday',
    label: 'Event Start Date'
}) }}

{{ form.field('registration_deadline', {
    field: 'date',
    default: '-1 week',  {# 1 week before event #}
    label: 'Registration Deadline',
    help: 'Default is 1 week before event'
}) }}

{{ form.field('early_bird_deadline', {
    field: 'date',
    default: '-2 weeks',  {# 2 weeks before event #}
    label: 'Early Bird Deadline'
}) }}

{# Task management with dynamic defaults #}
{{ cms.form.date('task-due', {}, {
    default: '+3 days',
    label: 'Task Due Date',
    help: 'Default is 3 days from today'
}) }}

{# Subscription renewal #}
{{ cms.form.date('renewal-date', {}, {
    default: '+1 year',
    label: 'Renewal Date',
    help: 'Annual subscription renewal'
}) }}
```

#### Date Fields with onCreate/onUpdate Settings

Date fields can also be configured to automatically update:

```json
{
    "type": "date",
    "label": "Created Date",
    "settings": {
        "onCreate": true  // Automatically set to current date when object is created
    }
}

{
    "type": "date", 
    "label": "Last Modified",
    "settings": {
        "onUpdate": true  // Automatically update to current date when object is modified
    }
}
```

### ID Auto-generation

Configure automatic ID generation based on other field values:

```json
{
    "autogen": "${title}"           // Generate from title field
}
```

#### Special autogen variables:

* `now` - Current date/time
* `timestamp` - Unix timestamp
* `uuid` - Unique identifier

```json
{
    "autogen": "${title}-${now}"    // Example: "my-post-2024-01-15"
}
```

## Options for Select/List Fields

### Example 1: Simple list of options

```php
['Option 1', 'Option 2', 'Option 3']
```

### Example 2: Options with values

```php
[
    ['value' => '1', 'label' => 'Option 1'],
    ['value' => '2', 'label' => 'Option 2'],
    ['value' => '3', 'label' => 'Option 3']
]
```

### Example 3: Grouped options

```php
[
    'Group 1' => ['Option 1', 'Option 2'],
    'Group 2' => ['Option 3', 'Option 4']
]
```

### Example 4: Grouped options with values

```php
[
    'Group 1' => [
        ['value' => '1', 'label' => 'Option 1'],
        ['value' => '2', 'label' => 'Option 2']
    ],
    'Group 2' => [
        ['value' => '3', 'label' => 'Option 3'],
        ['value' => '4', 'label' => 'Option 4']
    ]
]
```

### Dynamic Options

#### AutoBuild Options via Collection Data

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

#### Sorting Options

Sort options alphabetically in select/list fields:

```json
{
    "sortOptions": true
}
```

#### Property Options

Populate options from all unique values of a property:

```json
{
    "propertyOptions": true
}
```

Example with custom options:

```twig
{{ cms.form.select("myselect", {}, {
    options: {
        "1": "One",
        "2": "Two",
        "3": "Three"
    }
}) }}
```

#### Relational Options

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

Complete example:

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

#### Using Options in Twig

```twig
{% set options = [
    {value: "dog",     label: "Dog"},
    {value: "cat",     label: "Cat"},
    {value: "hamster", label: "Hamster"},
    {value: "parrot",  label: "Parrot"},
    {value: "spider",  label: "Spider"},
    {value: "goldfish", label: "Goldfish"}
] %}

{# Use with form builder #}
{% set form = cms.form.builder('pets') %}
{{ form.field('pet', {
    field: 'select',
    options: options
}) }}
```

## Specialized Form Methods

### Schema Forms

```twig
{# Create/edit schema forms #}
{{ cms.form.schema({
    id: 'my-schema-id'  # Optional: for editing existing schema
}) }}
```

### Collection Forms

```twig
{# Create/edit collection forms #}
{{ cms.form.collection({
    id: 'my-collection-id'  # Optional: for editing existing collection
}) }}
```

### Collection Data Table

```twig
{# Display collection data in a table #}
{{ cms.form.collectionTable('blog') }}
```

### Import Forms

```twig
{# Import data into a collection #}
{{ cms.form.importCollection('blog') }}

{# Import schema #}
{{ cms.form.importSchema() }}
```

### Job Queue Management

```twig
{# Display job queue statistics #}
{{ cms.form.jobqueueStats() }}

{# Job queue by status #}
{{ cms.form.jobqueueByStatus({
    header: 'Queue Status'
}) }}

{# Job queue by type #}
{{ cms.form.jobqueueByType({
    header: 'Queue Types'
}) }}

{# Clear queue form #}
{{ cms.form.clearqueue() }}
```

### Factory Forms

```twig
{# Factory form for bulk object creation #}
{{ cms.form.factory('blog') }}
```
