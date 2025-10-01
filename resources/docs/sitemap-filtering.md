# Sitemap Filtering

Total CMS sitemap builder supports simple property-based filtering using URL parameters.

## URL Parameters

### Filter (Include Only)
Include only objects where specified properties match values:

```
?filter=property:value                    # Single filter
?filter=property1:value1,property2:value2 # Multiple filters
?filter=property                          # Shorthand for property:true
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
?filter=featured        # Same as ?filter=featured:true
?exclude=draft          # Same as ?exclude=draft:true
```

## Real-World Examples

### Blog Posts
```
/sitemap/blog?exclude=draft              # Exclude draft posts
/sitemap/blog?filter=featured            # Only featured posts
/sitemap/blog?filter=published:true      # Only published posts
```

### Products
```
/sitemap/products?exclude=discontinued   # Exclude discontinued products
/sitemap/products?filter=instock         # Only in-stock products
/sitemap/products?filter=category:electronics,featured:true # Electronics + featured
```

### Events
```
/sitemap/events?exclude=cancelled        # Exclude cancelled events
/sitemap/events?filter=status:upcoming   # Only upcoming events
```

### Portfolio
```
/sitemap/portfolio?filter=published      # Only published work
/sitemap/portfolio?exclude=private       # Exclude private projects
```

## Value Types

The system automatically converts common values:

- `true` / `false` → Boolean values
- Other values → String comparison

```
?filter=featured:true     # Boolean true
?filter=status:published  # String "published"
?exclude=draft:false      # Boolean false
```

## Combining Filters

You can combine both filter and exclude in the same URL:

```
/sitemap/blog?filter=published&exclude=draft,private
```

This includes only published posts while excluding both draft and private posts.

## Backward Compatibility

All existing sitemaps continue to work unchanged. Filtering is only applied when URL parameters are provided.