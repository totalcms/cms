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

## Protected by Collection

The `protectedByCollection` setting controls the default value of the `protected` property for file and depot fields. When a file or depot is protected, it inherits the access control settings from its parent collection.

**Default Behavior:** Without this setting, all files and depots default to `protected: true`, meaning they inherit collection-level access control.

```json
{
	"protectedByCollection" : false
}
```

### When to Use

**Public file downloads (protected: false):**
```json
{
	"downloads": {
		"$ref"     : "https://www.totalcms.co/schemas/properties/file.json",
		"label"    : "Public Downloads",
		"settings" : {
			"protectedByCollection" : false
		}
	}
}
```

Use `false` when:
- Files should be publicly accessible regardless of collection access control
- Public downloads section on website
- Open-access resources (documentation, marketing materials)
- Files that don't contain sensitive information

**Protected file downloads (protected: true, default):**
```json
{
	"privateFiles": {
		"$ref"     : "https://www.totalcms.co/schemas/properties/depot.json",
		"label"    : "Private Documents",
		"settings" : {
			"protectedByCollection" : true
		}
	}
}
```

Use `true` (or omit the setting) when:
- Files should respect collection access control
- Member-only content
- Premium downloads
- Sensitive documents
- Private media libraries

### How It Works

The `protectedByCollection` setting determines the **default** value for new uploads:

1. **New File Upload:** Uses `protectedByCollection` setting value (or `true` if not set)
2. **Existing File:** Retains its current `protected` value regardless of the setting
3. **Manual Override:** Users can manually change the `protected` value for individual files in the admin interface

### Depot Field Example

For depot (multiple file) fields, the setting works the same way:

```json
{
	"publicGallery": {
		"$ref"     : "https://www.totalcms.co/schemas/properties/depot.json",
		"label"    : "Public Gallery",
		"settings" : {
			"protectedByCollection" : false,
			"rules" : {
				"filetype" : ["image/jpeg", "image/png"]
			}
		}
	}
}
```

### Important Notes

⚠️ **Existing Files:** This setting only affects the default for new uploads. Existing files retain their current `protected` value.

⚠️ **Manual Override:** Users can still manually change the `protected` flag for individual files in the file management interface, regardless of this setting.

✅ **Security:** Setting to `false` makes files publicly accessible. Use with caution for sensitive content.

## Image and Gallery Watermarks

Watermark settings allow you to automatically apply watermarks to images and gallery images. These settings are enforced at the image generation level and **cannot be bypassed via URL manipulation**, making them ideal for protecting photography and copyrighted content.

### Security Model

Watermark settings are enforced during image processing:
- ✅ **Cannot be removed** via URL parameters
- ✅ **Cannot be overridden** via URL parameters
- ✅ **Protects all image requests** (Twig templates, direct API access, etc.)
- ✅ **Maximum security** for photographers and content creators

### Available Watermark Options

#### Image Watermarks
```json
{
	"watermark": {
		"mark": "logo.png",
		"markw": "200",
		"markh": "100",
		"markpad": "10",
		"markpos": "bottom-right",
		"markalpha": 80
	}
}
```

- **mark** - Path to watermark image file
- **markw** - Watermark width (pixels or percentage like "50w")
- **markh** - Watermark height (pixels or percentage)
- **markpad** - Padding from edge in pixels
- **markpos** - Position: `top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`
- **markalpha** - Transparency (0-100, where 100 is fully opaque)

#### Text Watermarks
```json
{
	"watermark": {
		"marktext": "© 2024 Your Name",
		"marktextfont": "RobotoRegular",
		"marktextsize": 24,
		"marktextcolor": "ffffff",
		"marktextangle": 0,
		"marktextw": "100w",
		"marktextpad": "10",
		"marktextpos": "bottom-right",
		"marktextalpha": 80
	}
}
```

- **marktext** - Text to display as watermark
- **marktextfont** - Font name (TTF/OTF fonts from watermark-fonts depot, or "RobotoRegular" default)
- **marktextsize** - Font size in pixels
- **marktextcolor** - Text color in hex (without #)
- **marktextangle** - Rotation angle in degrees
- **marktextw** - Text width (pixels or percentage)
- **marktextpad** - Padding from edge in pixels
- **marktextpos** - Position (same options as image watermark)
- **marktextalpha** - Text transparency (0-100)

#### Combined Watermarks
You can use both image and text watermarks together:

```json
{
	"watermark": {
		"mark": "logo.png",
		"markpos": "bottom-left",
		"markalpha": 70,
		"marktext": "© 2024",
		"marktextpos": "bottom-right",
		"marktextsize": 18
	}
}
```

### Dimension-Based Watermark Control

The `limit` setting allows you to apply watermarks only to images above a certain size. This is perfect for showing clean thumbnails while protecting full-size images.

```json
{
	"watermark": {
		"marktext": "© Photography Studio",
		"marktextpos": "bottom-right",
		"limit": 800
	}
}
```

#### How the Limit Works

Watermarks are applied when:
- **No limit is set** - Always apply watermark ✅
- **No dimensions requested** (original image) - Always apply watermark ✅
- **Requested width > limit** - Apply watermark ✅
- **Requested height > limit** - Apply watermark ✅
- **Both width AND height ≤ limit** - No watermark ⛔

#### Example Behavior (with limit: 800)

| Image Request | Width | Height | Watermark? |
|--------------|-------|--------|------------|
| `?w=300&h=200` | 300 | 200 | ❌ No |
| `?w=300` | 300 | auto | ❌ No |
| `?h=600` | auto | 600 | ❌ No |
| `?w=1200&h=600` | 1200 | 600 | ✅ Yes |
| `?w=600&h=1000` | 600 | 1000 | ✅ Yes |
| No parameters | Original | Original | ✅ Yes |

### Real-World Examples

#### Photography Portfolio
Small thumbnails without watermarks, full images protected:

```json
{
	"gallery": {
		"$ref": "https://www.totalcms.co/schemas/properties/gallery.json",
		"settings": {
			"watermark": {
				"marktext": "© John Doe Photography",
				"marktextpos": "bottom-right",
				"marktextsize": 20,
				"marktextcolor": "ffffff",
				"marktextalpha": 80,
				"limit": 800
			}
		}
	}
}
```

Usage in templates:
```twig
{# Thumbnail - no watermark #}
{{ cms.gallery(id, {w: 300, h: 200}) }}

{# Full size - watermarked #}
{{ cms.gallery(id, {w: 1200}, {w: 1920}) }}
```

#### Stock Photography
Centered watermark with transparency for all images:

```json
{
	"image": {
		"$ref": "https://www.totalcms.co/schemas/properties/image.json",
		"settings": {
			"watermark": {
				"mark": "watermark-logo.png",
				"markpos": "center",
				"markalpha": 50,
				"markw": "40w"
			}
		}
	}
}
```

#### E-commerce Product Images
"Sample" watermark on large product images only:

```json
{
	"image": {
		"$ref": "https://www.totalcms.co/schemas/properties/image.json",
		"settings": {
			"watermark": {
				"marktext": "SAMPLE",
				"marktextpos": "center",
				"marktextsize": 72,
				"marktextcolor": "ff0000",
				"marktextalpha": 30,
				"limit": 1000
			}
		}
	}
}
```

### Custom Watermark Fonts

To use custom fonts for text watermarks:

1. Upload TTF or OTF fonts to a depot collection (default: `watermark-fonts`)
2. Reference the font by name (with or without `.ttf` extension):

```json
{
	"watermark": {
		"marktext": "© Photography Studio",
		"marktextfont": "CustomFont",
		"marktextsize": 24
	}
}
```

Or with extension:
```json
{
	"watermark": {
		"marktextfont": "CustomFont.ttf"
	}
}
```

The system will automatically load fonts from the depot. If the font is not found, it falls back to the default RobotoRegular font.

### Important Notes

⚠️ **Security**: Watermark settings are enforced server-side during image generation. Users cannot bypass watermarks by manipulating URLs.

⚠️ **Limit Setting**: The `limit` setting cannot be overridden via URL parameters - it's schema-only for security.

⚠️ **Performance**: Small thumbnails below the limit threshold skip watermark processing for better performance.

✅ **Flexibility**: Watermark settings provide the perfect balance between user experience (clean thumbnails) and content protection (watermarked full-size images).

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

## Access Group Options

Automatically populate form field options with all available access groups from the system. This is useful for assigning access control to objects, collections, or any entity that needs group-based permissions.

```json
{
	"accessGroupOptions" : true
}
```

The options will be formatted as `"id - description"` for easy identification. For example:
- `"admin - Administrators"`
- `"editors - Content Editors"`
- `"members - Site Members"`

### Common Use Cases

**Assigning access control to collections:**
```json
{
	"accessGroup": {
		"type"     : "string",
		"label"    : "Access Group",
		"field"    : "select",
		"settings" : {
			"accessGroupOptions" : true
		}
	}
}
```

**Multiple access groups (using list field):**
```json
{
	"accessGroups": {
		"type"     : "array",
		"label"    : "Access Groups",
		"field"    : "list",
		"settings" : {
			"accessGroupOptions" : true
		}
	}
}
```

**With sorting enabled:**
```json
{
	"accessGroup": {
		"type"     : "string",
		"label"    : "Access Group",
		"field"    : "select",
		"settings" : {
			"accessGroupOptions" : true,
			"sortOptions"        : true
		}
	}
}
```

### Field Types

Access group options work with any field that supports options or datalist:
- **select** - Dropdown selection (single choice)
- **list** - Multiple selection with search
- **radio** - Radio button groups
- Any text input field with datalist support

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

### Code Fields for Embed Codes and Third-Party Widgets

**IMPORTANT:** When using code fields for third-party embed codes (like TidyCal, Google Analytics, social media widgets, etc.), you **must disable HTML sanitization** to preserve scripts and data attributes.

By default, Total CMS sanitizes all HTML content for security, which removes:
- `<script>` tags
- `data-*` attributes
- Event handlers
- Dangerous protocols

This protects against XSS attacks but breaks third-party embed codes that rely on these features.

**Recommended Usage:**

Use the `code.json` property reference and set `htmlclean: false`:

```json
{
  "embedCode": {
    "$ref"     : "https://www.totalcms.co/schemas/properties/code.json",
    "label"    : "Embed Code",
    "field"    : "code",
    "settings" : {
      "htmlclean" : false,
      "mode"      : "html"
    }
  }
}
```

**Alternative (without property reference):**

```json
{
  "embedCode": {
    "type"     : "string",
    "label"    : "Third-Party Embed Code",
    "field"    : "code",
    "settings" : {
      "htmlclean" : false,
      "mode"      : "html"
    }
  }
}
```

**Examples of embed codes that require `htmlclean: false`:**

```html
<!-- TidyCal scheduling widget -->
<div class="tidycal-embed" data-path="username/consultation"></div>
<script src="https://asset-tidycal.b-cdn.net/js/embed.js" async></script>

<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=GA_MEASUREMENT_ID"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
</script>

<!-- Social media widgets, video embeds, etc. -->
```

⚠️ **Security Note:** Only use `htmlclean: false` for code fields where you control the content or trust the source. Never allow untrusted users to submit content to fields with HTML sanitization disabled.

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

The `visibility` setting controls when a field is displayed in forms based on the value of another field. This setting is available on **all field types** and allows you to conditionally show or hide fields.

### Conditional Visibility Syntax

```json
{
	"visibility": {
		"watch": "fieldName",
		"value": "expectedValue",
		"operator": "=="
	}
}
```

**Properties:**

- **`watch`** (required) - The name of the field to watch for changes
- **`value`** (required) - The value(s) to compare against. Can be a single value or an array of values
- **`operator`** (optional) - The comparison operator to use (default: `==`)

### Supported Operators

**Equality Operators:**
- `==` - Equals (default)
- `!=` - Not equals

**Numeric Operators:**
- `>` - Greater than
- `<` - Less than
- `>=` - Greater than or equal
- `<=` - Less than or equal

**Array Operators:**
- `in` - Current value is in the expected value array
- `not_in` - Current value is not in the expected value array

**Special Operators (for array fields like checkbox, multiselect):**
- `empty` - Field array is empty
- `not_empty` - Field array has at least one value

### Examples

**Show field when another field has a specific value:**
```json
{
	"visibility": {
		"watch": "linkType",
		"value": "custom",
		"operator": "=="
	}
}
```

**Hide field when another field is checked:**
```json
{
	"visibility": {
		"watch": "useDefaultDescription",
		"value": "1",
		"operator": "!="
	}
}
```

**Show field when value is NOT in a list:**
```json
{
	"visibility": {
		"watch": "userRole",
		"value": ["guest", "basic"],
		"operator": "not_in"
	}
}
```

**Show field when multiselect contains a value:**
```json
{
	"visibility": {
		"watch": "contentTypes",
		"value": "gallery",
		"operator": "in"
	}
}
```

**Show field based on numeric comparison:**
```json
{
	"visibility": {
		"watch": "orderTotal",
		"value": "100",
		"operator": ">="
	}
}
```

**Show field when array field is not empty:**
```json
{
	"visibility": {
		"watch": "categories",
		"value": null,
		"operator": "not_empty"
	}
}
```

**Match multiple values (OR logic):**
```json
{
	"visibility": {
		"watch": "deliveryMethod",
		"value": ["standard", "express", "overnight"],
		"operator": "=="
	}
}
```
Field is visible if `deliveryMethod` matches ANY value in the array.

### Default Behavior

Fields with a `visibility` setting are **hidden by default** until the condition is met. This ensures fields appear only when they should, even on initial form load.
