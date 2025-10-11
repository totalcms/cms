# Sitemap Filtering

Total CMS sitemap builder supports simple property-based filtering using URL parameters.

## URL Parameters

### Include (Include Only)
Include only objects where specified properties match values:

```
?include=property:value                    # Single include filter
?include=property1:value1,property2:value2 # Multiple include filters
?include=property                          # Shorthand for property:true
```

### Exclude (Remove)
Exclude objects where specified properties match values:

```
?exclude=property:value                    # Single exclusion
?exclude=property1:value1,property2:value2 # Multiple exclusions
?exclude=property                          # Shorthand for property:true
```

## Shorthand Syntax

When no value is provided, the property defaults to `:true`:

```
?include=featured       # Same as ?include=featured:true
?exclude=draft          # Same as ?exclude=draft:true
```

## Real-World Examples

### Blog Posts
```
/sitemap/blog?exclude=draft              # Exclude draft posts
/sitemap/blog?include=featured           # Only featured posts
/sitemap/blog?include=published:true     # Only published posts
```

### Products
```
/sitemap/products?exclude=discontinued   # Exclude discontinued products
/sitemap/products?include=instock        # Only in-stock products
/sitemap/products?include=category:electronics,featured:true # Electronics + featured
```

### Events
```
/sitemap/events?exclude=cancelled        # Exclude cancelled events
/sitemap/events?include=status:upcoming  # Only upcoming events
```

### Portfolio
```
/sitemap/portfolio?include=published     # Only published work
/sitemap/portfolio?exclude=private       # Exclude private projects
```

## Value Types

The system automatically converts common values:

- `true` / `false` → Boolean values
- Other values → String comparison

```
?include=featured:true    # Boolean true
?include=status:published # String "published"
?exclude=draft:false      # Boolean false
```

## Combining Filters

You can combine both include and exclude in the same URL:

```
/sitemap/blog?include=published&exclude=draft,private
```

This includes only published posts while excluding both draft and private posts.

## Backward Compatibility

All existing sitemaps continue to work unchanged. Filtering is only applied when URL parameters are provided.

The legacy `filter` parameter is still supported for backwards compatibility and is automatically mapped to `include`.