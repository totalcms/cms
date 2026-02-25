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

## Deck Item Forms

Deck forms allow you to create and edit individual items within a deck property. Deck properties contain multiple sub-items, each following a schema referenced by `deckref`.

### Understanding Deck Properties

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

### Auto-Built Deck Forms

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

### Deck Form Builder

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

### Deck Form Options

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

### How Deck Forms Work

1. **Auto-detection**: The form automatically detects the `deckref` from the property's schema
2. **Schema Loading**: Loads the deck schema (e.g., `feature-item.json`) for field definitions
3. **Data Loading**: If `itemId` is provided, loads existing deck item data
4. **API Routes**: Automatically sets correct routes:
   - **New items**: `POST /collections/{collection}/{id}/{property}/deck`
   - **Edit items**: `PUT /collections/{collection}/{id}/{property}/deck/{itemId}`

### Query Parameter Support

Deck forms support automatic ID detection from URL parameters:

```twig
{# Form will read ?id=product-123&itemId=feature-1 from URL #}
{{ cms.form.deck('products', '', 'features') }}
```

This is useful for admin interfaces where IDs come from the URL.

### Real-World Examples

#### Example 1: Product Features Manager

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

#### Example 2: Custom Layout with Form Builder

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

#### Example 3: Portfolio Project Items

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

### Best Practices

1. **Always provide collection, parent ID, and property name** for deck forms
2. **Use `itemId` in options** to edit existing items
3. **Set appropriate actions** to redirect users after save/delete
4. **Leverage form builder** for complex custom layouts
5. **Use query parameters** for admin interfaces with URL-based routing

### Common Use Cases

- **Product Features** - Individual features for products
- **FAQ Items** - Question/answer pairs for FAQ pages
- **Team Members** - Individual team member profiles
- **Portfolio Items** - Project details in a portfolio
- **Testimonials** - Individual customer testimonials
- **Timeline Events** - Events in a timeline
- **Pricing Tiers** - Individual pricing plan details

## Form Options

### General Form Options

All Total CMS form methods accept `formSettings` to control form behavior, appearance, and functionality:

#### Core Form Options

```twig
{{ cms.form.builder('products', {
    method     : 'POST',           # HTTP method (string, default: 'POST')
    class      : 'custom-form',    # CSS classes for form (string, default: '')
    buildError : 'Save failed',    # Error message to display (string, default: '')
    helpStyle  : 'popup',          # Help text style : 'popup',      etc. (string, default: '')
    save       : 'Save Product',   # Save button label (string, default: '')
    delete     : 'Delete Product', # Delete button label (string, default: '')
    formType   : 'object',         # Form type : 'collection', 'schema', 'object' (string, default: '')
    schema     : 'product',        # Schema name to use (string, default: '')
    route      : '/custom/submit', # Custom form submission route (string)
    label      : 'Submit',         # Button label for simple forms (string)
    refresh    : true,             # Refresh page after submission (bool, default: false)
    data       : {},               # Pre-populated form data (array, default: [])
}) }}
```

#### Form Behavior Options

```twig
{{ cms.form.builder('products', {
    autosave: true,                # Enable automatic saving (bool, default: false)
    helpOnHover: true,             # Show help on hover (bool, default: false)
    helpOnFocus: false,            # Show help on focus (bool, default: false)
    hideID: false,                 # Hide the ID field (bool, default: false)
    addOnly: true,                 # Security: Only allow creating new objects, never editing (bool, default: false)
}) }}
```

#### Security: Add Only Forms

Use `addOnly: true` for forms on the public side of your website to prevent users from editing existing objects by manipulating URL parameters:

```twig
{# Public registration form - secure against ID manipulation #}
{{ cms.form.builder('users', {
    addOnly: true,
    newActions: [
        {
            action: 'redirect',
            link: '/login'
        }
    ]
}).addField('name').addField('email').addField('password', {field: 'password'}).build() }}
```

**Security Note:** When `addOnly` is enabled:
- Any ID parameter in the URL or form is ignored
- The form will always create a new object, never update existing ones
- Protects against malicious users passing `?id=123` to edit other users' data

#### Action Configuration Options

Configure form actions for different operations. Actions are arrays that support multiple sequential operations:

```twig
{{ cms.form.builder('products', {
    newActions: [                   # Actions for new items (array, default: [])
        {
            action: 'redirect-object',
            link: '?id='
        }
    ],
    editActions: [                  # Actions for editing (array, default: [])
        {
            action: 'refresh'
        }
    ],
    deleteActions: [                # Actions for deletion (array, default: [])
        {
            action: 'redirect',
            link: '/admin/products'
        }
    ]
}) }}
```

##### Multiple Sequential Actions

You can chain multiple actions that will execute sequentially. By default, if one action fails, subsequent actions won't execute:

```twig
{{ cms.form.builder('products', {
    newActions: [
        {
            action: 'message',
            text: 'Product created successfully!'
        },
        {
            action: 'redirect-object',
            link: '?id='
        }
    ]
}) }}
```

##### Continue on Failure

Add `continue: true` to any action to continue executing subsequent actions even if that action fails. This is useful for optional actions like webhooks, analytics tracking, or notifications that shouldn't block critical user-facing actions.

**Behavior:**
- Failed actions with `continue: true` are logged to the browser console with warnings
- Form state remains "success" - users won't see error messages
- Subsequent actions continue executing normally
- Perfect for fire-and-forget operations

**Basic Example:**
```twig
{{ cms.form.builder('products', {
    newActions: [
        {
            action: 'webhook',
            link: 'https://api.example.com/notify',
            continue: true  # If webhook fails, still redirect
        },
        {
            action: 'redirect-object',
            link: '?id='
        }
    ]
}) }}
```

**Real-World Example - Analytics & Notifications:**
```twig
{{ cms.form.builder('orders', {
    newActions: [
        {
            action: 'webhook',
            link: 'https://analytics.example.com/track',
            continue: true  # Don't block on analytics failure
        },
        {
            action: 'webhook',
            link: 'https://slack.example.com/notify',
            continue: true  # Don't block on Slack notification failure
        },
        {
            action: 'webhook',
            link: 'https://email.example.com/send',
            continue: true  # Don't block on email failure
        },
        {
            action: 'redirect-object',
            link: '/orders/{id}'  # Always redirect user to order page
        }
    ]
}) }}
```

**Mixed Critical & Optional Actions:**
```twig
{{ cms.form.builder('products', {
    newActions: [
        {
            action: 'webhook',
            link: 'https://api.inventory.com/reserve'
            # No continue - inventory reservation is critical
        },
        {
            action: 'webhook',
            link: 'https://api.analytics.com/track',
            continue: true  # Analytics is optional
        },
        {
            action: 'redirect-object',
            link: '/products/{id}'
        }
    ]
}) }}
```

**Console Output:**
When an action with `continue: true` fails, you'll see:
```
Action execution failed: Error: Action webhook failed: 503 Service Unavailable
Action failed but continuing due to continue: true {action: 'webhook', link: '...', continue: true}
```

##### Available Action Types

- **redirect**: Redirect to a specific URL
  ```twig
  {action: 'redirect', link: '/admin/products'}
  ```

- **redirect-object**: Redirect to URL with object ID substitution
  ```twig
  {action: 'redirect-object', link: '/admin/products/{id}'}
  ```

- **refresh**: Refresh the current page
  ```twig
  {action: 'refresh'}
  ```

- **message**: Display a message (future: combined with other actions)
  ```twig
  {action: 'message', text: 'Operation successful!'}
  ```

#### Auto-Applied CSS Classes

The form system automatically applies CSS classes based on options:

- `.autosave` - when `autosave: true`
- `.help-on-hover` - when `helpOnHover: true`
- `.help-on-focus` - when `helpOnFocus: true`
- `.help-{helpStyle}` - when `helpStyle` is set (e.g., `.help-popup`)
- `.edit-mode` - when method is not 'POST'
- `.formgrid` - when schema has formgrid configuration

#### Form State Classes

Forms automatically receive state classes during the save lifecycle that you can use for custom styling:

| Class | Description |
|-------|-------------|
| `.unsaved` | Form has unsaved changes |
| `.processing` | Form is being saved |
| `.success` | Form was saved successfully |
| `.error` | An error occurred during save |
| `.actions-completed` | All post-save actions (webhooks, mailers, redirects) completed successfully |

**Note:** The `.actions-completed` class is added *in addition to* `.success`, not as a replacement. This allows you to show different feedback for "saved" vs "all done":

```css
form.success {
    /* Data saved to server */
    border-color: green;
}

form.success.actions-completed {
    /* All actions completed (emails sent, webhooks fired, etc.) */
    background-color: #e8f5e9;
}
```

#### Disabling the Status Banner

By default, Total CMS displays a full-screen status banner overlay when forms are processing, saved, or encounter errors. You can disable this banner for individual forms by adding the `no-status-banner` class:

```twig
{# Disable the global status banner for this form #}
{{ cms.form.builder('products', {
    class: 'no-status-banner'
}).addField('title').addField('price').build() }}
```

When `no-status-banner` is set:
- The global overlay banner won't appear for this form
- The form element still receives all state classes (`.processing`, `.success`, `.error`, `.unsaved`, `.actions-completed`)
- You have full control over styling the form's feedback

**Custom Styling Example:**

```css
/* Custom feedback for forms without the status banner */
form.no-status-banner {
    position: relative;
    transition: border-color 0.3s ease;
}

form.no-status-banner.unsaved {
    border-color: orange;
}

form.no-status-banner.processing {
    border-color: blue;
    opacity: 0.7;
    pointer-events: none;
}

form.no-status-banner.processing::after {
    content: "Saving...";
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(0,0,0,0.8);
    color: white;
    padding: 1rem 2rem;
    border-radius: 4px;
}

form.no-status-banner.success {
    border-color: green;
}

form.no-status-banner.success::after {
    content: "Saved!";
    /* ... styling ... */
}

form.no-status-banner.success.actions-completed::after {
    content: "All done!";
    /* ... styling ... */
}

form.no-status-banner.error {
    border-color: red;
}
```

**Use Cases:**
- Embedded forms where a full-screen overlay is disruptive
- Multiple forms on a page where you want individual feedback
- Custom-designed forms that need specific visual feedback
- Modal/dialog forms where the overlay conflicts with the modal

#### Simple Form Options

For `cms.form.simple()` method:

```twig
{{ cms.form.simple('/api/contact', content, {
    method: 'POST',                # HTTP method (string, default: 'POST')
    label: 'Send Message',         # Submit button label (string)
    refresh: true,                 # Refresh page after submission (bool, default: false)
    class: 'contact-form',         # CSS classes (string, default: '')
    csrfManager: csrfManager       # CSRF token manager instance
}) }}
```

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
