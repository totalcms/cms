# Sitemap Builder

Total CMS provides automatic XML sitemap generation for all collections with built-in filtering capabilities.

## Basic Usage

Generate a sitemap for any collection:

```
/sitemap/{collection}
```

For example:
```
/sitemap/blog
/sitemap/products
/sitemap/portfolio
```

## Filtering Objects

The sitemap builder supports powerful filtering using URL parameters to control which objects appear in your sitemap.

> **📖 See [Index Filter Documentation](docs/api/index-filter) for complete filtering syntax and examples.**

### Quick Examples

```
# Exclude draft posts
/sitemap/blog?exclude=draft

# Only featured posts
/sitemap/blog?include=featured

# Only published, non-private posts
/sitemap/blog?include=published&exclude=draft,private

# Only in-stock products
/sitemap/products?include=instock&exclude=discontinued
```

### Filter Syntax Overview

- **Include**: `?include=property:value` - Object must match ALL criteria
- **Exclude**: `?exclude=property:value` - Object excluded if it matches ANY criteria
- **Shorthand**: `?include=featured` - Defaults to `featured:true`
- **Multiple**: Use commas to separate multiple filters

### Common Use Cases

**Blog Posts:**
```
/sitemap/blog?include=published&exclude=draft,archived
```

**E-commerce:**
```
/sitemap/products?include=instock:true&exclude=discontinued
```

**Events:**
```
/sitemap/events?include=status:upcoming&exclude=cancelled
```

## Additional Options

### Date Property

Specify which date field to use for `lastmod` (defaults to `updated`):

```
/sitemap/blog?date=created
/sitemap/products?date=lastModified
```

## Advanced Filtering

For detailed filtering documentation including:
- Include/exclude logic
- Boolean and string values
- Multiple criteria
- Precedence rules
- PHP usage examples

**See the complete [Index Filter Documentation](docs/api/index-filter).**

## Backward Compatibility

All existing sitemaps continue to work unchanged. Filtering is only applied when URL parameters are provided.

The legacy `filter` parameter is still supported and is automatically mapped to `include`.