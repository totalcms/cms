# Docs for Field Settings


## Image and File Validation

The following JSON is sample settings that you can use for image and file validation rules
for uploads. You do you need to supply all rules. You can pick and choose which rules you
want to use.

```json
{
	"rules" : {
		"size"        : {"min":0,"max":300},
		"height"      : {"min":500,"max":1000},
		"width"       : {"min":500,"max":1000},
		"size"        : {"min":0,"max":1000},
		"count"       : {"max":10},
		"orientation" : "landscape",
		"aspectratio" : "4:3",
		"filetype"    : ["image/jpeg", "image/png"],
		"filename"    : ["image.jpg"],
	}
}
```

## ID autogen

For the ID field, you can use the following setting to autogenerate the id from one or
multiple fields. You can use standard Javascript string interpolation to inject field
values. You simply need to use the property name in the autogen value. There are also
a few special variables that you can use below.


```json
{
	"autogen" : "${title}-${now}"
}
```

special autogen vars

* **now** - Current timestamp in milliseconds (e.g., 1692123456789)
* **timestamp** - Current date/time in ISO format without colons/dashes (e.g., 20230815T143056)
* **uuid** - Real UUID v4 format (e.g., 550e8400-e29b-41d4-a716-446655440000)
* **uid** - Short random alphanumeric string (e.g., a4k7m2x)
* **oid** - Object ID counter (increments with each new object in collection)
* **oid-00000** - Zero-padded Object ID (e.g., oid-00001, oid-12345)

### Autogen Special Variables Examples

**Using timestamp for date-based IDs:**
```json
{
	"autogen" : "${title}-${timestamp}"
}
```
Generates: `my-post-20230815T143056`

**Using now for unique numeric IDs:**
```json
{
	"autogen" : "item-${now}"
}
```
Generates: `item-1692123456789`

**Using uuid for unique IDs:**
```json
{
	"autogen" : "${title}-${uuid}"
}
```
Generates: `my-post-550e8400-e29b-41d4-a716-446655440000`

**Using uid for short random IDs:**
```json
{
	"autogen" : "${title}-${uid}"
}
```
Generates: `my-post-a4k7m2x`

### OID (Object ID) Examples

The `oid` placeholder provides automatic sequential numbering based on the collection's object count:

```json
{
	"autogen" : "item-${oid}"
}
```
Generates: `item-1`, `item-2`, `item-3`, etc.

**Zero-padded OID:**
```json
{
	"autogen" : "product-${oid-00000}"
}
```
Generates: `product-00001`, `product-00002`, `product-00003`, etc.

**Different padding lengths:**
```json
{
	"autogen" : "${oid-000}"
}
```
Generates: `001`, `002`, `003`, etc.

**Combined with other placeholders:**
```json
{
	"autogen" : "${title}-${oid-00}"
}
```
Generates: `my-title-01`, `another-title-02`, etc.

The OID counter automatically increments each time a new object is created in the collection, ensuring unique sequential IDs.

## Sorting Options

You can sort the options in all form inputs that support options or datalist with the following setting

```json
{
	"sortOptions" : true
}
```

## Select Field Clear Button

Select fields include a clear button (×) that appears when a value is selected, allowing users to quickly reset the selection. This feature is enabled by default but can be disabled using the `clearValue` setting.

```json
{
	"clearValue" : false
}
```

When enabled (default), a circular clear button appears on the right side of the select field whenever a value is selected. Clicking it clears the selection and returns to the placeholder state.

## Property Options

Get all value of a field and populate as select options or datalist

```json
{
	"propertyOptions" : true
}
```

## Relational Options

The default value of the options is always the ID of the object. This is useful to
list all of the objects from another collection.

```json
{
  "relationalOptions" : {
  	"collection" : "feed",
  	"label"      : "title",
  	"value"      : "id"
  }
}
```

### Multiple Fields in Label

You can combine multiple fields in the label using the `join` parameter. This allows you to display more descriptive labels by combining multiple properties from the related object.

```json
{
  "relationalOptions" : {
  	"collection" : "authors",
  	"label"      : "firstName lastName",
  	"value"      : "id",
  	"join"       : " "
  }
}
```

In this example, the label will display "John Doe" by combining the `firstName` and `lastName` fields with a space. The `join` parameter defaults to a single space `" "` if not specified.

### Advanced Examples

**Combine with separator:**
```json
{
  "relationalOptions" : {
  	"collection" : "products",
  	"label"      : "name, category",
  	"value"      : "id",
  	"join"       : ", "
  }
}
```
This will create labels like "Product Name - Category Name".

**Three fields:**
```json
{
  "relationalOptions" : {
  	"collection" : "users",
  	"label"      : "firstName,lastName,email",
  	"value"      : "id",
  	"join"       : ","
  }
}
```
This will create labels like "John | Doe | john@example.com".

### Filtering Relational Options

You can filter which objects appear in relational dropdowns using `include` and `exclude` filters. This is useful for showing only published content, excluding drafts, or filtering by any object property.

> **📖 See [Index Filter Documentation](index-filter.md) for complete filtering syntax and examples.**

**Basic filtering:**
```json
{
  "relationalOptions" : {
  	"collection" : "blog",
  	"label"      : "title",
  	"value"      : "id",
  	"exclude"    : "draft:true"
  }
}
```
This will show all blog posts except drafts in the dropdown.

**Include only specific items:**
```json
{
  "relationalOptions" : {
  	"collection" : "products",
  	"label"      : "name",
  	"value"      : "id",
  	"include"    : "instock:true"
  }
}
```
This will show only in-stock products.

**Combined filters:**
```json
{
  "relationalOptions" : {
  	"collection" : "blog",
  	"label"      : "title",
  	"value"      : "id",
  	"include"    : "published:true",
  	"exclude"    : "draft:true,archived:true"
  }
}
```
This will show only published posts that are not drafts or archived.

**Shorthand syntax:**
```json
{
  "relationalOptions" : {
  	"collection" : "events",
  	"label"      : "name",
  	"value"      : "id",
  	"include"    : "featured",
  	"exclude"    : "cancelled"
  }
}
```
When no value is provided, it defaults to `true` (e.g., `featured` = `featured:true`).

**Array field filtering:**
```json
{
  "relationalOptions" : {
  	"collection" : "blog",
  	"label"      : "title",
  	"value"      : "id",
  	"include"    : "tags:featured",
  	"exclude"    : "tags:archived"
  }
}
```
Filters work with array fields like `tags` or `categories` by checking if the value exists in the array.

#### Filter Logic

- **include** - Object must match ALL specified criteria (AND logic)
- **exclude** - Object is excluded if it matches ANY criteria (OR logic)
- **Precedence** - Exclude takes precedence over include
- **Array fields** - Checks if value exists within array (case-insensitive for strings)
- **String values** - Case-insensitive matching for flexibility
- **Boolean values** - Strict comparison for optimal performance

Multiple filters are comma-separated: `"exclude": "draft:true,private:true"`

## Smart Date Defaults

Use the following settings can be used for a date field to auto-populate it when an
object is created or updated.

```json
{
  "onUpdate" : true
}
```

```json
{
  "onCreate" : true
}
```

## Styled Text

Styled text supports [a lot of settings](https://froala.com/wysiwyg-editor/docs/options/ "external").
Here is an example.


```json
{
  "toolbarButtons" : [
    ["bold", "italic", "underline", "strikeThrough", "subscript", "superscript"],
    ["fontFamily", "fontSize", "textColor", "backgroundColor"],
    ["inlineClass", "inlineStyle", "clearFormatting"],
    ["html"]
  ],
  "inlineClasses" : {
    "fr-class-code"         : "Code",
    "fr-class-highlighted"  : "Highlighted",
    "fr-class-transparency" : "Transparent"
  },
  "linkStyles" : {
    "button" : "Button",
  },
  "imageUploadParams" : { "p" : "styledtext" },
  "imageMaxSize"      : 1048576,
  "confirmDelete"     : false,
  "pastePlain"        : true
}
```

## All Text Input Fields (text, url, tel, phone, password, etc.)

The following can be used on text fields to limit the number of characters.

```json
{
  "maxlength" : 100,
  "minlength" : 10,
  "pattern"   : "/ab+c/",
  "readonly"  : true,
  "disabled"  : true,
  "class"     : "custom-class"
}
```

## Textarea

```json
{
  "rows" : 10,
}
```

## Numbers Fields

```json
{
  "min"  : 1,
  "max"  : 10,
  "step" : 0.25,
}
```

## Purifying HTML in Text

Default all text will be scanned for HTML and sanitized to help prevent from XSS attacks.
You can disable this by setting the following.

```json
{
  "htmlclean" : false
}
```

## SVG

Default all svgs will be sanitized to help prevent from XSS attacks.
You can disable this by setting the following.

```json
{
  "svgclean" : false
}
```


## Lists

```json
{
  "removeItemButton"      : true,
  "duplicateItemsAllowed" : false,
  "addChoices"            : true,
  "maxItemCount"          : -1,
  "asString"              : false
}
```

## Code Editor

The code field provides a syntax-highlighted code editor powered by CodeMirror. It supports multiple programming languages and can be customized with various settings.

```json
{
  "mode"          : "twig",
  "theme"         : "elegant",
  "lineNumbers"   : true,
  "lineWrapping"  : true,
  "indentUnit"    : 2,
  "tabSize"       : 2,
  "foldGutter"    : true,
  "matchBrackets" : true,
  "autoCloseTags" : true
}
```

### Available Settings

- **mode** - The syntax highlighting mode. Supported values:
  - `"twig"` - Twig templating
  - `"html"` or `"htmlmixed"` - HTML with embedded CSS/JS
  - `"css"` - CSS stylesheets
  - `"javascript"` or `"js"` - JavaScript
  - `"php"` - PHP code
  - `"markdown"` - Markdown text
  - Any other CodeMirror mode name

- **theme** - The color theme. Default is `"elegant"` (light theme)

- **lineNumbers** - Show line numbers in the gutter. Default: `true`

- **lineWrapping** - Wrap long lines. Default: `true`

- **indentUnit** - Number of spaces per indentation level. Default: `2`

- **tabSize** - Width of a tab character. Default: `2`

- **foldGutter** - Enable code folding in the gutter. Default: `true`

- **matchBrackets** - Highlight matching brackets. Default: `true`

- **autoCloseTags** - Auto-close HTML/XML tags. Default: `true`

### Example Usage

```json
{
  "snippet": {
    "type"     : "string",
    "label"    : "Code Snippet",
    "field"    : "code",
    "settings" : {
      "mode"         : "javascript",
      "theme"        : "elegant",
      "lineNumbers"  : true,
      "indentUnit"   : 4
    }
  }
}
```

## Radio Field

Radio fields allow users to select a single option from multiple choices. They support grid layouts for better organization when you have many options.

### Grid Layout Settings

Use the `fieldGrid` setting to specify the minimum width for each radio option in the grid. By default, radio options display in a single column (full width). When you specify a `fieldGrid` value, the options will automatically flow into a responsive grid layout.

```json
{
    "fieldGrid": "250px"
}
```

This creates a responsive grid where:
- Each radio option has a minimum width of `250px`
- Options automatically wrap to new rows when needed
- Grid adjusts based on container width

## Price Field

Price fields are specialized number fields for monetary values. They automatically have a step of `0.01` (hardcoded) for proper decimal handling and display with a currency icon.

### Currency Icons

Price fields display with a dollar sign icon by default. You can change the currency icon using the `class` setting with one of the supported currency icon classes:

```json
{
	"class": "icon-dollar"
}
```

```json
{
	"class": "icon-euro"
}
```

```json
{
	"class": "icon-pound"
}
```

```json
{
	"class": "icon-yen"
}
```

## Field Visibility

The `visibility` setting controls when and where a field is displayed in the admin interface. This setting is available on **all field types** and allows you to show or hide fields based on context.

### Available Visibility Options

```json
{
	"visibility": "form"
}
```

**Supported Values:**

- **`"form"`** (default) - Field appears in forms (create/edit object pages) and in list views
- **`"form-only"`** - Field appears only in forms, hidden from list views
- **`"list-only"`** - Field appears only in list views, hidden from forms
- **`"hidden"`** - Field is completely hidden from both forms and lists (useful for system fields)

### Common Use Cases

**Hide technical fields from list view:**
```json
{
	"id": {
		"type": "string",
		"label": "ID",
		"settings": {
			"visibility": "form-only"
		}
	}
}
```
This keeps the ID field editable in forms but removes clutter from list views.

**Show computed fields only in lists:**
```json
{
	"objectCount": {
		"type": "number",
		"label": "Object Count",
		"settings": {
			"visibility": "list-only"
		}
	}
}
```
Display-only fields that don't need editing can be shown in lists but hidden from forms.

**Completely hide system fields:**
```json
{
	"internalStatus": {
		"type": "string",
		"label": "Internal Status",
		"settings": {
			"visibility": "hidden"
		}
	}
}
```
System-managed fields can be hidden from users while remaining accessible to the API.

### Default Behavior

If no `visibility` setting is specified, fields use the default `"form"` visibility, appearing in both forms and list views. This is the most common behavior for editable content fields.

### Combining with Other Settings

Visibility works alongside other field settings:

```json
{
	"slug": {
		"type": "string",
		"label": "URL Slug",
		"settings": {
			"autogen": "${title}",
			"readonly": true,
			"visibility": "form-only"
		}
	}
}
```

This creates an auto-generated, read-only slug field that appears in forms but not in list views.
