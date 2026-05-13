---
title: "JumpStart"
description: "Bootstrap new Total CMS projects with JumpStart data import and export for collections, schemas, objects, and Faker-based factory test data."
related:
  - collections/import
  - collections/export
  - twig/factory
---

# JumpStart

JumpStart is Total CMS's data import/export system that allows you to quickly set up new projects with
predefined content structures, collections, schemas, and sample data. Think of it as a blueprint for your
CMS that can be shared, modified, and reused across different projects.

**IMPORTANT**: JumpStart is not a full backup of your data. JumpStart will remove all images, galleries, files
and depots from your data. Please refer to the Export to Zip in each of your Collections. This will include
all data from that collection.

## What JumpStart Can Be Used For

### 🚀 **Quick Project Setup**
- Bootstrap new projects with predefined collections and schemas
- Import sample content for development and testing
- Create consistent project structures across multiple sites

### 📋 **Content Templates**
- Share content structures between projects
- Create starter templates for specific industries or use cases
- Distribute pre-configured CMS setups to clients or team members

### 🔄 **Data Migration**
- Export data from one Total CMS instance and import to another
- Backup and restore content structures
- Transfer configurations between development, staging, and production

### 🎯 **Demo and Training**
- Provide sample data for demonstrations
- Create training environments with realistic content
- Showcase CMS capabilities with pre-populated data

## JumpStart Structure

A JumpStart definition is a JSON file containing:

```json
{
  "version": "1.0.0",
  "name": "My Project Template",
  "description": "A template for...",
  "collections": {
    "reserved": ["blog", "gallery", "text"],
    "custom": [...]
  },
  "schemas": [...],
  "objects": [...],
  "factory": [...]
}
```

### Reserved Collection Overrides

`reserved` entries can be either a plain string id (creates the collection with defaults) or an object with overrides. The object form keeps the built-in schema binding but lets you customise the URL, sorting, name, and other collection settings:

```json
{
  "collections": {
    "reserved": [
      "gallery",
      { "id": "blog", "url": "/blog/{id}", "sortBy": "date:desc" }
    ]
  }
}
```

Common overrides:

| Field | Purpose |
|-------|---------|
| `url` | Public URL pattern. Supports template placeholders (e.g. `/blog/{id}`); templated URLs are automatically pretty. |
| `prettyUrl` | Toggle pretty URLs for non-templated URL prefixes (ignored when `url` contains placeholders). |
| `sortBy` | Default sort (e.g. `date:desc`, `title:asc`). |
| `name` | Display name shown in the admin sidebar. |

## Export Behavior

When you export your current CMS data to JumpStart format:

### ✅ **What Gets Exported**
- **All Objects**: Every object from every collection is added to the `objects` array
- **Collection Definitions**: Both reserved and custom collection configurations
- **Custom Schemas**: Any custom schemas you've created
- **Collection Settings**: Labels, sorting, and other collection configurations

### ❌ **What Gets Removed**
- **Images**: Image files are stripped out (only metadata remains)
- **Galleries**: Gallery images are removed (gallery structure remains)
- **Files**: Uploaded files are removed from file objects
- **Depot**: Uploaded files are removed from depot objects

### 🔧 **What Supports Factory Generation**
- **Images**: Can use `"image": "imageBlur"` factory rules
- **Galleries**: Can use factory rules to generate sample gallery items

> **💡 Tip**: You may want to review and clean up the exported objects before creating your final JumpStart
> definition. Consider moving repetitive objects to the factory array for easier maintenance.

## Working with the Factory Array

The factory array allows you to generate multiple objects with fake data instead of storing each individual
object. This is more efficient and flexible than having dozens of similar objects in the objects array.

### Basic Factory Configuration

```json
{
  "factory": [
    {
      "collection": "blog",
      "count": 10,
      "data": {
        "title": "sentence",
        "content": "paragraphs",
        "date": "date",
        "featured": "boolean"
      }
    }
  ]
}
```

### Factory with Specific ID

```json
{
  "factory": [
    {
      "collection": "text",
      "id": "welcome-message",
      "data": {
        "text": "Welcome to my site!"
      }
    }
  ]
}
```

### Moving Objects to Factory

Instead of having many similar objects, you can:

1. **Export your data** to see the objects array
2. **Identify repetitive objects** (like multiple blog posts or products)
3. **Move them to factory** configuration
4. **Update the data rules** to use Faker generators

**Before (objects array):**
```json
{
  "objects": [
    {
      "collection": "blog",
      "id": "post-1",
      "data": {"title": "My First Post", "content": "Lorem ipsum..."}
    },
    {
      "collection": "blog",
      "id": "post-2",
      "data": {"title": "Another Post", "content": "More content..."}
    }
    // ...more similar posts
  ]
}
```

**After (factory array):**
```json
{
  "factory": [
    {
      "collection": "blog",
      "count": 5,
      "data": {
        "title": "sentence",
        "content": "paragraphs",
        "featured": "boolean"
      }
    }
  ]
}
```

### Available Faker Rules

Common Faker rules you can use in factory data:

- **Text**: `"word"`, `"sentence"`, `"paragraph"`, `"paragraphs"`
- **Numbers**: `"randomFloat(2, 10, 100)"`, `"numberBetween(1, 100)"`
- **Dates**: `"date"`, `"dateTimeBetween('-1 year', 'now')"`
- **Names**: `"name"`, `"firstName"`, `"lastName"`
- **Contact**: `"email"`, `"phoneNumber"`, `"address"`
- **Web**: `"url"`, `"domainName"`
- **Images**: `"imageBlur"` (for filtered image generation)
- **Lists**: `"tags(1, 5)"` (generates 1-5 tag items)

### Factory Filtering

During import, certain field types are automatically filtered:

- **Image fields** are skipped during factory generation to avoid file dependencies
- **File fields** are skipped for the same reason
- **Gallery fields** support factory generation but files are filtered

## Using JumpStart

### Exporting Your Data

1. Go to **[Admin Utils → JumpStart](/admin/utils/jumpstart)**
2. Click **Export Current Data**
3. Review and edit the exported JSON file as needed
4. Save your customized JumpStart definition

### Importing JumpStart Data

Use the API endpoint to import JumpStart definitions:

```bash
POST /import/jumpstart
Content-Type: application/json

{
  "version": "1.0.0",
  "name": "My Template",
  // ... your jumpstart definition
}
```

### Demo JumpStart

Total CMS includes a demo JumpStart file at `resources/jumpstart/demo.json` that showcases various collection types and demonstrates best practices for structuring JumpStart data.

## Best Practices

### 📝 **Planning Your JumpStart**
- Start with essential collections and schemas
- Use factory for repetitive content
- Keep unique objects in the objects array
- Document your JumpStart purpose in the description field

### 🧹 **Cleaning Up Exports**
- Remove test or temporary objects before finalizing
- Convert similar objects to factory rules
- Update factory data to use appropriate Faker rules
- Test your JumpStart definition on a fresh install

### 🔄 **Maintenance**
- Version your JumpStart definitions
- Update factory rules as your content structure evolves
- Test imports regularly to ensure compatibility
- Document any custom requirements or setup steps

---

**Related Documentation:**
- [Factory Documentation](docs/twig/factory) - Learn more about factory data generation
- [Import/Export](docs/collections/import) - General import/export functionality
- [Data Model](docs/advanced/data-model) - Understanding Total CMS data structures