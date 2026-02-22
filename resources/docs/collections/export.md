# Exporting Data

Total CMS provides comprehensive data export functionality to help you backup, migrate, or integrate your content with external systems.

## Export Formats

Total CMS supports multiple export formats:

- **JSON** - Full fidelity export with all metadata
- **CSV** - Spreadsheet-compatible format
- **ZIP** - Bundled exports with media files

## Filtering Exports

Both JSON and CSV exports support `include` and `exclude` query parameters to limit which objects are exported. This uses the same [Index Filtering](docs/api/index-filter) system available in sitemaps and RSS feeds.

### URL Parameters

```
?include=property:value       # Only export objects matching ALL criteria
?exclude=property:value       # Skip objects matching ANY criteria
?include=featured             # Shorthand for featured:true
```

### Examples

```bash
# Export only published blog posts as JSON
GET /export/collections/blog/json?include=published:true

# Export blog posts excluding drafts as CSV
GET /export/collections/blog/csv?exclude=draft

# Export featured products that are in stock
GET /export/collections/products/json?include=featured:true,instock:true

# Combine include and exclude
GET /export/collections/blog/json?include=published&exclude=draft,private
```

When no filter parameters are provided, all objects in the collection are exported (existing behavior).

For full details on filter syntax, value types, and logic, see the [Index Filtering documentation](docs/api/index-filter).

## Exporting from Admin Panel

### Collection Export

1. Go to the collection view
2. Click "Export" button in toolbar
3. Choose export options:
   - Format (JSON, CSV)
4. Click "Export"
