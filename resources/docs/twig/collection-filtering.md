# Collection Filtering and Sorting

Total CMS provides powerful `filterCollection` and `sortCollection` Twig filters for advanced data manipulation. These filters allow you to perform complex filtering operations on collections using a variety of operators, making it easy to create dynamic, filtered content displays.

## filterCollection Filter

The `filterCollection` filter allows you to filter arrays of objects based on complex criteria. It uses the CollectionRefiner service under the hood.

### Basic Syntax

```twig
{% set filtered = collection | filterCollection([
    {
        property: "field_name",
        operator: "operator_name",
        value: "filter_value"
    }
]) %}
```

Multiple filters can be combined - all filters must match (AND logic):

```twig
{% set filtered = collection | filterCollection([
    {property: "status", operator: "equal", value: "published"},
    {property: "date", operator: "past", value: ""}
]) %}
```

---

## String Operators

### `equal`
Exact match (case-sensitive for strings, loose comparison).

```twig
{% set published = cms.collection.objects('blog') | filterCollection([
    {property: "status", operator: "equal", value: "published"}
]) %}
```

### `contains`
Check if string contains substring (case-sensitive).

```twig
{% set guides = cms.collection.objects('blog') | filterCollection([
    {property: "title", operator: "contains", value: "Guide"}
]) %}
```

### `starts`
Check if string starts with prefix.

```twig
{% set tutorials = cms.collection.objects('blog') | filterCollection([
    {property: "title", operator: "starts", value: "How to"}
]) %}
```

### `ends`
Check if string ends with suffix.

```twig
{% set questions = cms.collection.objects('blog') | filterCollection([
    {property: "title", operator: "ends", value: "?"}
]) %}
```

### `like`
Regular expression pattern matching.

```twig
{% set posts = cms.collection.objects('blog') | filterCollection([
    {property: "title", operator: "like", value: "PHP.*Tutorial"}
]) %}
```

### Case-Insensitive Variants

All string operators have case-insensitive versions:
- `equalCaseInsensitive`
- `containsCaseInsensitive`
- `startsCaseInsensitive`
- `endsCaseInsensitive`

```twig
{% set posts = cms.collection.objects('blog') | filterCollection([
    {property: "title", operator: "containsCaseInsensitive", value: "guide"}
]) %}
{# Matches "Guide", "GUIDE", "guide", etc. #}
```

---

## Comparison Operators

### `less` / `lt`
Less than comparison.

```twig
{% set cheapProducts = cms.collection.objects('products') | filterCollection([
    {property: "price", operator: "less", value: "50"}
]) %}
```

### `lesseq` / `le`
Less than or equal to.

```twig
{% set affordableProducts = cms.collection.objects('products') | filterCollection([
    {property: "price", operator: "lesseq", value: "100"}
]) %}
```

### `greater` / `gt`
Greater than comparison.

```twig
{% set premiumProducts = cms.collection.objects('products') | filterCollection([
    {property: "price", operator: "greater", value: "100"}
]) %}
```

### `greatereq` / `ge`
Greater than or equal to.

```twig
{% set expensiveProducts = cms.collection.objects('products') | filterCollection([
    {property: "price", operator: "greatereq", value: "500"}
]) %}
```

---

## Boolean Operators

### `istrue`
Check if value is true (true, 'true', '1', or 1).

```twig
{% set featured = cms.collection.objects('products') | filterCollection([
    {property: "featured", operator: "istrue"}
]) %}
```

### `isfalse`
Check if value is false (false, 'false', '0', or 0).

```twig
{% set notFeatured = cms.collection.objects('products') | filterCollection([
    {property: "featured", operator: "isfalse"}
]) %}
```

### `isempty`
Check if value is empty.

```twig
{% set noDescription = cms.collection.objects('products') | filterCollection([
    {property: "description", operator: "isempty"}
]) %}
```

### `isnotempty`
Check if value is not empty.

```twig
{% set hasDescription = cms.collection.objects('products') | filterCollection([
    {property: "description", operator: "isnotempty"}
]) %}
```

---

## Date Operators

### Basic Date Operators

**`past`** - Date is in the past
```twig
{% set pastEvents = cms.collection.objects('events') | filterCollection([
    {property: "date", operator: "past"}
]) %}
```

**`future`** - Date is in the future
```twig
{% set upcomingEvents = cms.collection.objects('events') | filterCollection([
    {property: "date", operator: "future"}
]) %}
```

**`today`** - Date is today
```twig
{% set todaysEvents = cms.collection.objects('events') | filterCollection([
    {property: "date", operator: "today"}
]) %}
```

**`pastToday`** - Date is today or in the past
```twig
{% set currentAndPast = cms.collection.objects('events') | filterCollection([
    {property: "date", operator: "pastToday"}
]) %}
```

**`futureToday`** - Date is today or in the future
```twig
{% set currentAndFuture = cms.collection.objects('events') | filterCollection([
    {property: "date", operator: "futureToday"}
]) %}
```

### Date Range Operators

**`todayPlusDays`** - Today through N days in future
```twig
{# Events from today through next 7 days #}
{% set nextWeekEvents = cms.collection.objects('events') | filterCollection([
    {property: "date", operator: "todayPlusDays", value: 7}
]) %}
```

**`todayMinusDays`** - Today and N days back
```twig
{# Blog posts from last 30 days including today #}
{% set recentPosts = cms.collection.objects('blog') | filterCollection([
    {property: "date", operator: "todayMinusDays", value: 30}
]) %}
```

### Date Comparison Operators

**`after`** - Date is after another date
```twig
{% set recent = cms.collection.objects('blog') | filterCollection([
    {property: "date", operator: "after", value: "2024-01-01"}
]) %}
```

**`before`** - Date is before another date
```twig
{% set archived = cms.collection.objects('blog') | filterCollection([
    {property: "date", operator: "before", value: "2023-01-01"}
]) %}
```

---

## Calendar Period Operators

**`thisWeek`** - Date is in current week (Monday-Sunday)
```twig
{% set thisWeeksPosts = cms.collection.objects('blog') | filterCollection([
    {property: "date", operator: "thisWeek"}
]) %}
```

**`thisMonth`** - Date is in current month
```twig
{% set thisMonthsPosts = cms.collection.objects('blog') | filterCollection([
    {property: "date", operator: "thisMonth"}
]) %}
```

**`thisYear`** - Date is in current year
```twig
{% set thisYearsPosts = cms.collection.objects('blog') | filterCollection([
    {property: "date", operator: "thisYear"}
]) %}
```

---

## Numeric Range Operators

**`between`** - Value is between min and max (inclusive)

Usage: `value: "min,max"`

```twig
{# Products priced between $10 and $100 #}
{% set affordableProducts = cms.collection.objects('products') | filterCollection([
    {property: "price", operator: "between", value: "10,100"}
]) %}

{# Cars with mileage between 10k-50k miles #}
{% set usedCars = cms.collection.objects('cars') | filterCollection([
    {property: "mileage", operator: "between", value: "10000,50000"}
]) %}

{# Ratings between 3-5 stars #}
{% set topRated = cms.collection.objects('reviews') | filterCollection([
    {property: "rating", operator: "between", value: "3,5"}
]) %}
```

---

## Text Length Operators

**`longerThan`** - Text exceeds N characters
```twig
{# Blog posts with detailed content (over 500 chars) #}
{% set detailedPosts = cms.collection.objects('blog') | filterCollection([
    {property: "content", operator: "longerThan", value: 500}
]) %}
```

**`shorterThan`** - Text is under N characters
```twig
{# Products with short descriptions for grid view #}
{% set compactProducts = cms.collection.objects('products') | filterCollection([
    {property: "description", operator: "shorterThan", value: 200}
]) %}
```

---

## Array Counting Operators

**`hasMin`** - Array has at least N items
```twig
{# Posts with at least 3 tags #}
{% set wellTaggedPosts = cms.collection.objects('blog') | filterCollection([
    {property: "tags", operator: "hasMin", value: 3}
]) %}

{# Products with multiple images #}
{% set multiImageProducts = cms.collection.objects('products') | filterCollection([
    {property: "gallery", operator: "hasMin", value: 2}
]) %}
```

**`hasMax`** - Array has at most N items
```twig
{# Products with 5 or fewer images #}
{% set simpleProducts = cms.collection.objects('products') | filterCollection([
    {property: "gallery", operator: "hasMax", value: 5}
]) %}
```

**`hasCount`** - Array has exactly N items
```twig
{# Events with exactly 2 speakers #}
{% set dualSpeakerEvents = cms.collection.objects('events') | filterCollection([
    {property: "speakers", operator: "hasCount", value: 2}
]) %}
```

---

## Day-of-Week Operators

**`isWeekday`** - Date is Monday through Friday
```twig
{# Business hours events only #}
{% set businessEvents = cms.collection.objects('events') | filterCollection([
    {property: "date", operator: "isWeekday"}
]) %}
```

**`isWeekend`** - Date is Saturday or Sunday
```twig
{# Weekend activities #}
{% set weekendEvents = cms.collection.objects('events') | filterCollection([
    {property: "date", operator: "isWeekend"}
]) %}
```

**`dayOfWeek`** - Date is specific day of week

Value can be day name (Monday-Sunday) or number (1=Monday, 7=Sunday):

```twig
{# Tuesday specials #}
{% set tuesdaySpecials = cms.collection.objects('specials') | filterCollection([
    {property: "date", operator: "dayOfWeek", value: "Tuesday"}
]) %}

{# Using day number (1=Mon, 7=Sun) #}
{% set mondayEvents = cms.collection.objects('events') | filterCollection([
    {property: "date", operator: "dayOfWeek", value: "1"}
]) %}
```

---

## Array Logic (OR vs AND)

When filtering with an array of values, you can control whether items must match **ANY** value (OR logic) or **ALL** values (AND logic) using the `logic` parameter.

### OR Logic (Default)

Returns items that match **ANY** of the values in the array:

```twig
{# Posts tagged with 'php' OR 'javascript' OR 'web' #}
{% set posts = cms.collection.objects('blog') | filterCollection([
    {
        property: "tags",
        operator: "contains",
        value: ["php", "javascript", "web"],
        logic: "or"  {# Default - can be omitted #}
    }
]) %}

{# Products in category 'electronics' OR 'computers' #}
{% set products = cms.collection.objects('products') | filterCollection([
    {
        property: "category",
        operator: "equal",
        value: ["electronics", "computers"]
        {# logic: "or" is the default #}
    }
]) %}
```

### AND Logic

Returns items that match **ALL** of the values in the array:

```twig
{# Posts that contain ALL tags: 'php' AND 'framework' AND 'tutorial' #}
{% set advancedPosts = cms.collection.objects('blog') | filterCollection([
    {
        property: "tags",
        operator: "contains",
        value: ["php", "framework", "tutorial"],
        logic: "and"
    }
]) %}

{# Products with ALL specified features #}
{% set premiumProducts = cms.collection.objects('products') | filterCollection([
    {
        property: "features",
        operator: "contains",
        value: ["waterproof", "wireless", "fast-charging"],
        logic: "and"  {# Must have ALL three features #}
    }
]) %}
```

### Logic Summary

| Logic | Behavior | Use Case |
|-------|----------|----------|
| `"or"` (default) | Match **ANY** value | Broad filtering, multiple categories |
| `"and"` | Match **ALL** values | Strict requirements, feature completeness |

---

## Negation (NOT Operator)

Any operator can be negated by prefixing with `not-` or prepending the value with `!`:

```twig
{# Using not- prefix #}
{% set notPublished = cms.collection.objects('blog') | filterCollection([
    {property: "status", operator: "not-equal", value: "published"}
]) %}

{# Using ! prefix on value #}
{% set notPublished = cms.collection.objects('blog') | filterCollection([
    {property: "status", operator: "equal", value: "!published"}
]) %}

{# Not in the past (future or today) #}
{% set notPastEvents = cms.collection.objects('events') | filterCollection([
    {property: "date", operator: "not-past"}
]) %}
```

---

## sortCollection Filter

The `sortCollection` filter sorts arrays based on one or more properties.

### Basic Syntax

```twig
{% set sorted = collection | sortCollection([
    {
        property: "field_name",
        reverse: false,      {# Optional: true for descending #}
        natural: false,      {# Optional: true for natural sort #}
        shuffle: false       {# Optional: true to randomize #}
    }
]) %}
```

### Single Property Sort

```twig
{# Sort by date ascending #}
{% set sorted = cms.collection.objects('blog') | sortCollection([
    {property: "date"}
]) %}

{# Sort by price descending #}
{% set sorted = cms.collection.objects('products') | sortCollection([
    {property: "price", reverse: true}
]) %}
```

### Multi-Property Sort

Sorts are applied in order - first sort is primary, subsequent sorts break ties:

```twig
{# Sort by featured (desc), then date (desc), then title (natural) #}
{% set posts = cms.collection.objects('blog') | sortCollection([
    {property: "featured", reverse: true},
    {property: "date", reverse: true},
    {property: "title", natural: true}
]) %}
```

### Natural Sorting

Natural sorting treats numbers within strings intelligently:

```twig
{# Without natural: "Item 1", "Item 10", "Item 2" #}
{# With natural:    "Item 1", "Item 2", "Item 10" #}
{% set sorted = cms.collection.objects('products') | sortCollection([
    {property: "name", natural: true}
]) %}
```

### Random Shuffle

```twig
{# Randomize order #}
{% set randomProducts = cms.collection.objects('products') | sortCollection([
    {property: "name", shuffle: true}
]) %}
```

---

## manualSort Filter

The `manualSort` filter allows you to sort collections by an explicit order of values, with control over how remaining items are handled. This is useful when you need a specific custom order that can't be achieved with standard property sorting.

### Basic Syntax

```twig
{% set sorted = collection | manualSort({
    property: "field_name",
    order: ["value1", "value2", "value3"],
    remainder: {property: "name"},
    excludeRemainder: false
}) %}
```

### Options

| Option | Type | Description |
|--------|------|-------------|
| `property` | string | The property to match against the order array |
| `order` | array | Explicit list of values defining the sort order |
| `collection` | string | Collection ID to auto-lookup order from collection metadata |
| `remainder` | object | Sort rule for items not in the order array (same format as sortCollection) |
| `excludeRemainder` | boolean | If true, items not in the order array are excluded from results |

**Note:** When using `collection`, the filter looks up `manualSort.{property}` from the collection's metadata. You can use either `order` or `collection`, but `order` takes precedence if both are provided.

### Basic Manual Sort

```twig
{# Sort team members by role in specific order #}
{% set team = cms.collection.objects("team") | manualSort({
    property: 'role',
    order: ['ceo', 'cfo', 'cmo', 'vp', 'director']
}) %}
{# CEO first, CFO second, CMO third, etc. #}
{# Any roles not listed appear at the end in original order #}
```

### With Remainder Sorting

Items not matching the explicit order can be sorted by a secondary property:

```twig
{# Executives in order, then remaining staff sorted by lastName #}
{% set team = cms.collection.objects("team") | manualSort({
    property: 'position',
    order: ['ceo', 'cfo', 'cmo', 'vp'],
    remainder: {property: 'lastName'}
}) %}
```

### Exclude Non-Matching Items

Use `excludeRemainder` to only return items that match the order array:

```twig
{# Only show featured team members in specific order #}
{% set featured = cms.collection.objects("team") | manualSort({
    property: 'id',
    order: ['john-smith', 'jane-doe', 'bob-wilson'],
    excludeRemainder: true
}) %}
{# Returns exactly 3 items (if they exist) in that order #}
```

### Using Collection Metadata

Store sort orders in the collection's metadata for easy admin editing. Use the `collection` option to automatically look up the order:

```twig
{# Automatic lookup from collection metadata #}
{% set team = cms.collection.objects("team") | manualSort({
    property: 'position',
    collection: 'team',
    remainder: {property: 'lastName'}
}) %}
```

This is equivalent to manually fetching the metadata:

```twig
{# Manual lookup (same result) #}
{% set meta = cms.collection.get('team') %}
{% set team = cms.collection.objects("team") | manualSort({
    property: 'position',
    order: meta.manualSort.position | default([]),
    remainder: {property: 'lastName'}
}) %}
```

To configure the order, edit the collection settings in the admin and add JSON to the "Manual Sort Orders" field:

```json
{
    "position": ["ceo", "cfo", "cmo", "vp", "director", "manager"],
    "department": ["executive", "engineering", "sales", "marketing"]
}
```

### Sub-Sorting Within Groups

When multiple items have the same ordered value, the remainder rule sorts them:

```twig
{# If there are multiple VPs, sort them by name #}
{% set team = cms.collection.objects("team") | manualSort({
    property: 'role',
    order: ['ceo', 'vp', 'manager'],
    remainder: {property: 'name'}
}) %}
{# Result: CEO, then all VPs sorted by name, then managers sorted by name, then everyone else by name #}
```

### Practical Examples

**Portfolio with curated order:**
```twig
{% set projects = cms.collection.objects("projects") | manualSort({
    property: 'id',
    order: ['flagship-project', 'award-winner', 'client-favorite'],
    remainder: {property: 'date', reverse: true}
}) %}
{# Featured projects first, then remaining by date descending #}
```

**Navigation menu order:**
```twig
{% set pages = cms.collection.objects("pages") | manualSort({
    property: 'slug',
    order: ['home', 'about', 'services', 'portfolio', 'contact'],
    excludeRemainder: true
}) %}
{# Only these pages, in this exact order #}
```

**Product categories with priority:**
```twig
{% set meta = cms.collection.get('products') %}
{% set products = cms.collection.objects("products") | manualSort({
    property: 'category',
    order: meta.manualSort.category | default(['featured', 'new', 'sale']),
    remainder: {property: 'name', natural: true}
}) %}
```

---

## Real-World Examples

### Blog Post Management

```twig
{# Published posts from this year, sorted by date #}
{% set posts = cms.collection.objects('blog')
    | filterCollection([
        {property: "status", operator: "equal", value: "published"},
        {property: "date", operator: "thisYear"}
    ])
    | sortCollection([
        {property: "featured", reverse: true},
        {property: "date", reverse: true}
    ]) %}
```

### E-commerce Product Filtering

```twig
{# In-stock products, price $20-$100, with good ratings #}
{% set products = cms.collection.objects('products')
    | filterCollection([
        {property: "instock", operator: "istrue"},
        {property: "price", operator: "between", value: "20,100"},
        {property: "rating", operator: "greatereq", value: "4"},
        {property: "description", operator: "longerThan", value: 50}
    ])
    | sortCollection([
        {property: "rating", reverse: true},
        {property: "price"}
    ]) %}
```

### Event Calendar

```twig
{# This week's events on weekdays, sorted by date #}
{% set events = cms.collection.objects('events')
    | filterCollection([
        {property: "date", operator: "thisWeek"},
        {property: "date", operator: "isWeekday"},
        {property: "cancelled", operator: "isfalse"}
    ])
    | sortCollection([
        {property: "date"},
        {property: "start_time"}
    ]) %}
```

### Content Discovery

```twig
{# Well-tagged posts from last 30 days with detailed content #}
{% set qualityPosts = cms.collection.objects('blog')
    | filterCollection([
        {property: "date", operator: "todayMinusDays", value: 30},
        {property: "tags", operator: "hasMin", value: 3},
        {property: "content", operator: "longerThan", value: 1000},
        {property: "status", operator: "equal", value: "published"}
    ])
    | sortCollection([
        {property: "views", reverse: true},
        {property: "date", reverse: true}
    ]) %}
```

### Weekend Special Offers

```twig
{# Special offers valid this weekend #}
{% set weekendDeals = cms.collection.objects('specials')
    | filterCollection([
        {property: "active", operator: "istrue"},
        {property: "start_date", operator: "isWeekend"},
        {property: "discount", operator: "greatereq", value: "20"}
    ])
    | sortCollection([
        {property: "discount", reverse: true}
    ]) %}
```

### User-Driven Filters

```twig
{# Dynamic filtering based on URL parameters #}
{% set minPrice = get.min | default(0) %}
{% set maxPrice = get.max | default(1000) %}
{% set category = get.category | default('') %}

{% set products = cms.collection.objects('products')
    | filterCollection([
        {property: "price", operator: "between", value: minPrice ~ "," ~ maxPrice},
        {property: "category", operator: "equal", value: category}
    ])
    | sortCollection([
        {property: get.sort | default('price'), reverse: get.order == 'desc'}
    ]) %}
```

---

## Performance Tips

1. **Filter before sorting** - Reduce the dataset size before sorting
2. **Use specific operators** - More specific operators are faster
3. **Limit results** - Use `| slice(0, 10)` after filtering
4. **Cache filtered results** - For expensive operations

```twig
{# Good: Filter first, then sort, then limit #}
{% set results = cms.collection.objects('blog')
    | filterCollection([...])
    | sortCollection([...])
    | slice(0, 10) %}
```

---

## See Also

- [Twig Filters Reference](docs/twig/filters) - All available Twig filters
- [Index Filtering](docs/api/index-filter) - Server-side filtering for APIs
- [CMS Grid Tag](docs/twig/cmsgrid-tag) - Display filtered collections
