# Relational Options

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

## Multiple Fields in Label

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

## Advanced Examples

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

## Filtering Relational Options

You can filter which objects appear in relational dropdowns using `include` and `exclude` filters. This is useful for showing only published content, excluding drafts, or filtering by any object property.

> **See [Index Filter Documentation](docs/api/index-filter) for complete filtering syntax and examples.**

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

## Filter Logic

- **include** - Object must match ALL specified criteria (AND logic)
- **exclude** - Object is excluded if it matches ANY criteria (OR logic)
- **Precedence** - Exclude takes precedence over include
- **Array fields** - Checks if value exists within array (case-insensitive for strings)
- **String values** - Case-insensitive matching for flexibility
- **Boolean values** - Strict comparison for optimal performance

Multiple filters are comma-separated: `"exclude": "draft:true,private:true"`
