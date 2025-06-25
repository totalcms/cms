# Exporting Data

Total CMS provides comprehensive data export functionality to help you backup, migrate, or integrate your content with external systems.

## Export Formats

Total CMS supports multiple export formats:

- **JSON** - Full fidelity export with all metadata
- **CSV** - Spreadsheet-compatible format
- **ZIP** - Bundled exports with media files

## Exporting from Admin Panel

### Collection Export

1. Go to the collection view
2. Click "Export" button in toolbar
3. Choose export options:
   - Format (JSON, CSV)
4. Click "Export"

## Export via API

### Basic Export

```bash
# Export entire collection as JSON
GET /api/export/collections/{collection}

# Export with specific format
GET /api/export/collections/{collection}/csv
```
