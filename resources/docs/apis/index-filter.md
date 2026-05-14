---
title: "Index Filtering"
description: "Filter and sort Total CMS collection indexes with include, exclude, and sort criteria for sitemaps, RSS feeds, and API endpoints using boolean, string, and array values."
---

# Index Filtering

Total CMS provides a powerful `IndexFilter` service for filtering index objects based on include/exclude criteria. This filtering system is used throughout the CMS for sitemaps, RSS feeds, API endpoints, and custom implementations.

## Overview

The `IndexFilter` service provides a flexible way to filter collection objects using simple property-based criteria:

- **Include filters** - Object must match ALL specified criteria
- **Exclude filters** - Object is excluded if it matches ANY criteria
- **Sorting** - Sort results by any property, ascending or descending
- **Boolean & String values** - Automatic type conversion
- **Shorthand syntax** - Property name defaults to `true`

## Filter Syntax

### Include (Include Only)

Include only objects where specified properties match ALL values:

```
include=property:value                    # Single include filter
include=property1:value1,property2:value2 # Multiple include filters (AND logic)
include=property                          # Shorthand for property:true
```

**Logic:** ALL conditions must be true for the object to be included.

### Exclude (Remove)

Exclude objects where specified properties match ANY value:

```
exclude=property:value                    # Single exclusion
exclude=property1:value1,property2:value2 # Multiple exclusions (OR logic)
exclude=property                          # Shorthand for property:true
```

**Logic:** If ANY condition matches, the object is excluded.

### Search

Full-text search across all fields of each object:

```
search=term                    # Single term search
search=term1 term2             # Multiple terms (AND logic by default)
search=term1 and term2         # Explicit AND (same as above)
search=term1 or term2          # OR logic (match any term)
search="exact phrase"          # Quoted phrase (matches exact sequence)
```

**Logic:**
- **Default (AND):** All terms must appear somewhere in the object (can be in different fields)
- **OR keyword:** At least one term must match
- **Quoted phrases:** Matched as an exact sequence within a single field value

**Matching behavior:**
- **Case-insensitive** — "Table" matches "table", "TABLE", etc.
- **Word boundary** — "table" matches "The table is here" but NOT "vegetable" or "reputable"
- **All fields searched** — Searches across every field value in the object (strings, numbers, arrays, nested objects)
- **Array fields** — Recursively searches within array and nested array values

**URL Parameters:**
```
?search=travel                          # Objects containing "travel"
?search=red table                       # Objects containing both "red" AND "table"
?search=red or blue                     # Objects containing "red" OR "blue"
?search="red table"                     # Objects containing the exact phrase "red table"
?search=travel&include=published:true   # Combine search with filters
```

**PHP Code:**
```php
$results = $pipeline->execute($items, [
    'search' => 'travel adventure',
    'include' => 'published:true',
]);
```

> **Note:** Search results are not cached. When a `search` parameter is present, the cache is bypassed to ensure accurate results.

## Sorting

Results can be sorted by any property using the `sort` option.

### Shorthand Format

Prefix the property name with `-` for descending order.

```
sort=property                    # Sort ascending by property
sort=-property                   # Sort descending by property
```

### Colon Format

Use `property:direction` for explicit control. Supports multi-criteria sorting with comma separation and optional natural sort.

```
sort=property:asc                # Sort ascending
sort=property:desc               # Sort descending
sort=date:desc,title:asc         # Multi-criteria: date descending, then title ascending
sort=title:asc:natural           # Natural sort (treats numbers in strings intelligently)
sort=shuffle                     # Random order
```

**URL Parameters:**
```
?sort=title                      # Sort by title A-Z
?sort=-date                      # Sort by date newest first
?sort=date:desc                  # Same as above (colon format)
?sort=date:desc,title:asc        # Multi-sort: newest first, then alphabetical
?sort=price                      # Sort by price low to high
?sort=-price                     # Sort by price high to low
```

**PHP Code:**
```php
// Sort blog posts by title ascending
$posts = $filter->fetchFilteredIndex('blog', [
    'sort' => 'title',
]);

// Sort by date descending
$posts = $filter->fetchFilteredIndex('blog', [
    'sort' => '-date',
]);

// Combine with filters
$posts = $filter->fetchFilteredIndex('blog', [
    'include' => 'published:true',
    'exclude' => 'draft:true',
    'sort'    => '-date',
]);
```

Sorting is applied after filtering, so only the matching objects are sorted. Sorting is supported in both `IndexFilter` (collections) and `DataViewFilter` (data views).

### Precedence

**Exclude takes precedence over include.** If an object matches an exclude filter, it will be removed even if it also matches an include filter.

```php
// Object: ['published' => true, 'draft' => true]
// Filters: include=published:true, exclude=draft:true
// Result: EXCLUDED (draft:true matches exclude)
```

## Value Types

The system automatically converts common values:

| Value | Type | Comparison | Example |
|-------|------|------------|---------|
| `true` | Boolean | Strict (===) | `published:true` |
| `false` | Boolean | Strict (===) | `draft:false` |
| Other | String | Case-insensitive | `status:active` |

**Comparison behavior:**
- **Boolean values** - Fast strict comparison for optimal performance
- **String values** - Case-insensitive matching for flexibility
- **Array fields** - Case-insensitive search within array

```
?include=featured:true      # Boolean true (strict match)
?include=status:published   # String "published" (matches "Published", "PUBLISHED", etc.)
?exclude=draft:false        # Boolean false (strict match)
?exclude=category:news      # String "news" (matches "News", "NEWS", etc.)
```

## Wildcard Patterns

String values support wildcard patterns using `*` for flexible matching. All wildcard comparisons are **case-insensitive**.

| Pattern | Matches | Example |
|---------|---------|---------|
| `*value*` | Contains "value" | `title:*hello*` matches "Say Hello World" |
| `value*` | Starts with "value" | `title:hello*` matches "Hello World" |
| `*value` | Ends with "value" | `title:*world` matches "Hello World" |
| `value` | Exact match | `title:hello` matches only "hello" |

**URL Parameters:**
```
?include=title:*adventure*              # Title contains "adventure"
?include=name:photo*                    # Name starts with "photo"
?exclude=category:*archived             # Category ends with "archived"
?include=tags:*land*                    # Tag contains "land" (e.g., "landscape", "iceland")
```

**PHP Code:**
```php
$objects = $filter->fetchFilteredIndex('blog', [
    'include' => 'title:*travel*',
    'exclude' => 'status:*draft',
]);
```

Wildcards work with both scalar string fields and array fields (like tags). When used with arrays, each item in the array is tested against the pattern.

## Array Field Support

When a field contains an array, the filter checks if the value exists **within** the array. String comparisons are **case-insensitive** for better usability. This is particularly useful for fields like tags, categories, or any multi-value properties.

**Example with tags:**
```
?include=tags:travel        # Matches if "travel" is in the tags array (case-insensitive)
?exclude=tags:archived      # Excludes if "archived" is in the tags array (case-insensitive)
```

**Sample data:**
```php
['id' => '1', 'tags' => ['Travel', 'Adventure', 'Europe']]
```

**Filter behavior (case-insensitive):**
- `include=tags:travel` → ✓ Matches ("travel" matches "Travel")
- `include=tags:ADVENTURE` → ✓ Matches ("ADVENTURE" matches "Adventure")
- `include=tags:food` → ✗ No match (food not in array)
- `exclude=tags:archived` → ✓ Included (archived not in array)
- `exclude=tags:europe` → ✗ Excluded ("europe" matches "Europe")

**Combined with scalar fields:**
```php
// Include published posts with "travel" tag
?include=published:true,tags:travel

// Exclude drafts or posts with "archived" tag
?exclude=draft:true,tags:archived
```

## Shorthand Syntax

When no value is provided, the property defaults to `:true`:

```
?include=featured       # Same as ?include=featured:true
?exclude=draft          # Same as ?exclude=draft:true
?include=published      # Same as ?include=published:true
```

## Usage in Code

### Basic Filtering

`IndexFilter` is available via dependency injection — it requires both an `IndexReader` and `ObjectFilter`, so let the DI container handle construction:

```php
use TotalCMS\Domain\Index\Service\IndexFilter;

// Inject via constructor
public function __construct(private IndexFilter $filter) {}

// Fetch and filter in one call
$objects = $this->filter->fetchFilteredIndex('blog', [
    'include' => 'published:true',
    'exclude' => 'draft:true'
]);
```

### Get Filtered IndexData

```php
// Returns IndexData with filtered objects
$indexData = $filter->fetchFilteredIndexData('blog', [
    'include' => 'featured:true',
    'exclude' => 'archived:true'
]);

// Access filtered objects
foreach ($indexData->objects as $object) {
    // Process filtered objects
}
```

### Filter Existing Array

```php
// If you already have objects
$objects = [
    ['id' => '1', 'published' => true, 'draft' => false],
    ['id' => '2', 'published' => true, 'draft' => true],
    ['id' => '3', 'published' => false, 'draft' => false],
];

$filtered = $filter->filterObjects($objects, [
    'include' => 'published:true',
    'exclude' => 'draft:true'
]);
// Result: Only object '1'
```

### Check Single Object

```php
$object = ['published' => true, 'featured' => true];

$matches = $filter->matchesFilter($object, [
    'include' => 'published:true,featured:true'
]);
// Result: true (matches all criteria)
```

## Real-World Examples

### Blog Posts

**URL Parameters:**
```
?exclude=draft                           # Exclude draft posts
?include=featured                        # Only featured posts
?include=published:true                  # Only published posts
?include=published&exclude=draft,private # Published, not draft or private
```

**PHP Code:**
```php
$posts = $filter->fetchFilteredIndex('blog', [
    'include' => 'published:true,featured:true',
    'exclude' => 'draft:true'
]);
```

### E-commerce Products

**URL Parameters:**
```
?exclude=discontinued                    # Exclude discontinued products
?include=instock:true                    # Only in-stock products
?include=category:electronics,featured   # Electronics category + featured
?include=instock&exclude=discontinued    # In stock, not discontinued
```

**PHP Code:**
```php
$products = $filter->fetchFilteredIndex('products', [
    'include' => 'instock:true,category:electronics',
    'exclude' => 'discontinued:true'
]);
```

### Events

**URL Parameters:**
```
?exclude=cancelled                       # Exclude cancelled events
?include=status:upcoming                 # Only upcoming events
?include=featured&exclude=soldout        # Featured, not sold out
```

**PHP Code:**
```php
$events = $filter->fetchFilteredIndex('events', [
    'include' => 'status:upcoming',
    'exclude' => 'cancelled:true,soldout:true'
]);
```

### Portfolio

**URL Parameters:**
```
?include=published                       # Only published work
?exclude=private                         # Exclude private projects
?include=category:webdesign,featured     # Web design + featured
```

**PHP Code:**
```php
$portfolio = $filter->fetchFilteredIndex('portfolio', [
    'include' => 'published:true,category:webdesign',
    'exclude' => 'private:true'
]);
```

### Array Fields (Tags, Categories)

**URL Parameters:**
```
?include=tags:travel                     # Posts with "travel" tag
?exclude=tags:archived                   # Exclude posts with "archived" tag
?include=tags:featured,published:true    # Featured tag + published
```

**PHP Code:**
```php
// Posts with "travel" tag that aren't drafts
$posts = $filter->fetchFilteredIndex('blog', [
    'include' => 'tags:travel',
    'exclude' => 'draft:true'
]);

// Products in "electronics" category that are in stock
$products = $filter->fetchFilteredIndex('products', [
    'include' => 'categories:electronics,instock:true'
]);

// Events with "featured" tag, excluding cancelled
$events = $filter->fetchFilteredIndex('events', [
    'include' => 'tags:featured',
    'exclude' => 'tags:cancelled,tags:soldout'
]);
```

## Advanced Examples

### Multiple Include Criteria (AND Logic)

All conditions must match:

```php
// Object must be published AND featured AND in electronics category
$objects = $filter->fetchFilteredIndex('products', [
    'include' => 'published:true,featured:true,category:electronics'
]);
```

### Multiple Exclude Criteria (OR Logic)

If ANY condition matches, object is excluded:

```php
// Exclude if draft OR archived OR deleted
$objects = $filter->fetchFilteredIndex('blog', [
    'exclude' => 'draft:true,archived:true,deleted:true'
]);
```

### Combined Include and Exclude

```php
// Must be published AND featured
// AND NOT draft AND NOT private
$objects = $filter->fetchFilteredIndex('blog', [
    'include' => 'published:true,featured:true',
    'exclude' => 'draft:true,private:true'
]);
```

### String Value Matching

```php
// Match specific string values
$objects = $filter->fetchFilteredIndex('products', [
    'include' => 'status:active,category:electronics',
    'exclude' => 'vendor:discontinued'
]);
```

### Boolean False Matching

```php
// Include only objects where draft is explicitly false
$objects = $filter->fetchFilteredIndex('blog', [
    'include' => 'draft:false'
]);
```

## Helper Methods

### Extract Filter Options

```php
$options = [
    'include' => 'published:true',
    'exclude' => 'draft:true',
    'limit'   => 10,
    'offset'  => 0
];

$filterOptions = $filter->extractFilterOptions($options);
// Returns: ['include' => 'published:true', 'exclude' => 'draft:true']
// Note: limit and offset remain in $options
```

### Parse Filter String

```php
$parsed = $filter->parseFilterString('published:true,featured:true,status:active');
// Returns:
// [
//     ['field' => 'published', 'value' => true],
//     ['field' => 'featured', 'value' => true],
//     ['field' => 'status', 'value' => 'active']
// ]
```

## Where It's Used

The `IndexFilter` service is used throughout Total CMS:

- **Sitemap Builder** - Filter which objects appear in XML sitemaps ([Sitemap Documentation](docs/collections/sitemap-builder))
- **Collection Index API** - Filter collection objects via API endpoint
- **RSS Feeds** - Control feed content based on object properties
- **Data Export** - Filter which objects are included in JSON and CSV exports ([Export Documentation](docs/collections/export))
- **Form Fields** - Filter relational dropdown options ([Field Settings](docs/fields/relational-options#filtering-relational-options))
- **Grid Display** - Filter objects in Twig templates
- **Gallery Launcher** - Filter gallery images via include/exclude/search ([Render Documentation](docs/twig/render#gallerylauncher))
- **Custom Services** - Build your own filtered collections

### Collection Index API

The collection index API endpoint supports filtering via URL parameters:

```
GET /collections/{collection}/index?include=published:true&exclude=draft:true
```

**Examples:**

```bash
# Get all published blog posts
GET /collections/blog/index?include=published:true

# Get featured products that are in stock
GET /collections/products/index?include=featured:true,instock:true

# Get events excluding cancelled
GET /collections/events/index?exclude=cancelled:true

# Get blog posts with "travel" tag
GET /collections/blog/index?include=tags:travel

# Complex filtering: published posts with travel tag, excluding drafts
GET /collections/blog/index?include=published:true,tags:travel&exclude=draft:true

# Full-text search
GET /collections/blog/index?search=adventure

# Search combined with filters
GET /collections/blog/index?search=adventure&include=published:true&exclude=draft:true
```

The API returns a filtered `IndexData` object with only matching objects.

## Best Practices

### 1. Use Specific Filters
```php
// Good - specific criteria
'include' => 'published:true,status:active'

// Less specific
'include' => 'published'
```

### 2. Exclude Early
Exclude filters run first for better performance:
```php
// Efficient - excluded objects skip include check
'exclude' => 'deleted:true,archived:true'
'include' => 'published:true'
```

### 3. Default to True
Use shorthand for boolean true values:
```php
// Concise
'include' => 'published,featured'

// Verbose but equivalent
'include' => 'published:true,featured:true'
```

### 4. Document Your Filters
When using filters in code, document the business logic:
```php
// Only show active, non-discontinued products to customers
$products = $filter->fetchFilteredIndex('products', [
    'include' => 'active:true,instock:true',
    'exclude' => 'discontinued:true'
]);
```

## Filter Logic Reference

### Include Logic (AND)
```
Object: {published: true, featured: true}
Filter: include=published:true,featured:true
Result: ✓ INCLUDED (both match)

Object: {published: true, featured: false}
Filter: include=published:true,featured:true
Result: ✗ EXCLUDED (featured doesn't match)
```

### Exclude Logic (OR)
```
Object: {draft: true, private: false}
Filter: exclude=draft:true,private:true
Result: ✗ EXCLUDED (draft matches)

Object: {draft: false, private: false}
Filter: exclude=draft:true,private:true
Result: ✓ INCLUDED (neither match)
```

### Precedence (Exclude First)
```
Object: {published: true, draft: true}
Filter: include=published:true, exclude=draft:true
Result: ✗ EXCLUDED (exclude takes precedence)
```

## Troubleshooting

### No Results

If filtering returns no results:

1. **Check field names** - Ensure properties exist in your objects
2. **Check values** - Boolean vs string comparison
3. **Check logic** - Remember include=AND, exclude=OR
4. **Check data** - Verify objects have expected values

```php
// Debug: Check filter options
$filterOptions = $filter->extractFilterOptions($options);
var_dump($filterOptions);

// Debug: Check parsed filters
$parsed = $filter->parseFilterString($options['include']);
var_dump($parsed);
```

### Unexpected Results

If filtering returns unexpected objects:

1. **Exclude precedence** - Exclude runs before include
2. **Missing fields** - Objects without the field won't match include filters
3. **Type mismatches** - 'true' (string) vs true (boolean)

```php
// Check single object
$matches = $filter->matchesFilter($object, $filterOptions);
var_dump($matches); // true or false
```

## See Also

- [URL Filters Utility](docs/twig/utils) - Convert URL query parameters into include/exclude/sort/search options for visitor-facing filtering
- [Sitemap Builder Documentation](docs/collections/sitemap-builder) - Using filters in sitemaps
- [Twig Integration](docs/twig/functions) - Using filters in templates
