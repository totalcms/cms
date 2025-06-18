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

* now
* timestamp
* uuid


## Sorting Options

You can sort the options in all form inputs that support options or datalist with the following setting

```json
{
	"sortOptions" : true
}
```

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
  	"value"      : "id",
  }
}
```

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

Styled text supports [a lot of settings](https://froala.com/wysiwyg-editor/docs/options/).
Here is an example.


```json
{
  "toolbarButtons" : [
    ["bold", "italic", "underline", "strikeThrough", "subscript", "superscript"],
    ["fontFamily", "fontSize", "textColor", "backgroundColor"],
    ["inlineClass", "inlineStyle", "clearFormatting"]
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
  "htmlpurify" : false
}
```

## Lists

```json
{
  "removeItemButton"      : true,
  "duplicateItemsAllowed" : false,
  "addChoices"            : true,
  "maxItemCount"          : -1,
}
```
