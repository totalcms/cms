---
title: "REST API Documentation"
description: "Complete Total CMS REST API reference for CRUD operations on collections, objects, images, files, and schemas with authentication and error handling."
related:
  - apis/api-keys
  - apis/index-filter
  - apis/php-api
---

# REST API Documentation

Total CMS provides a RESTful API for accessing content and schemas. This documentation covers all available endpoints with examples and response formats.

## API Overview

- **Base URL**: Your site's API root (e.g., `https://yoursite.com/api`)
- **Content Type**: `application/json`
- **Authentication**: API keys (Pro edition) or session-based

## Authentication

Total CMS supports two authentication methods for API access: **API Keys** (recommended for external applications, Pro edition required) and **Session Authentication** (for same-origin admin panel requests).

> **📖 For comprehensive API key documentation, see [API Keys Guide](docs/apis/api-keys)**

### API Key Authentication (Pro Edition)

API keys provide secure, token-based authentication ideal for headless CMS implementations, mobile apps, and third-party integrations.

**Using the X-API-Key header (recommended):**
```bash
curl -H "X-API-Key: tcms_1234567890abcdef1234567890abcdef" \
     -H "Content-Type: application/json" \
     https://yoursite.com/api/collections/blog
```

**Using query parameter:**
```bash
curl "https://yoursite.com/api/collections/blog?api_key=tcms_1234567890abcdef1234567890abcdef"
```

**Key Features:**
- **Scope-based permissions** - Control HTTP methods (GET, POST, PUT, DELETE, PATCH)
- **Path restrictions** - Limit access to specific collections or endpoints
- **Usage tracking** - Monitor last used timestamps
- **Easy revocation** - Delete keys to immediately revoke access

**Creating API Keys:**
Navigate to **Utilities** → **API Keys** in the admin interface, or visit `/admin/utils/api-keys`.

For detailed information on scopes, permissions, and best practices, see the [API Keys documentation](docs/apis/api-keys).

### Session Authentication

For admin panel and same-origin requests using cookies:

```javascript
// Include CSRF token for session-based requests
fetch('/api/collections/blog', {
    headers: {
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
        'Content-Type': 'application/json'
    }
});
```

**When to use session authentication:**
- Admin panel JavaScript
- Same-origin web applications
- Browser-based tools running on the same domain

**When to use API keys:**
- Mobile applications
- Third-party integrations
- Headless CMS implementations
- Automated scripts and workflows

## Collections API

### Get All Collections

```http
GET /api/collections
```

**Response:**
```json
{
    "collections": [
        {
            "name": "blog",
            "title": "Blog Posts",
            "count": 25,
            "schema": "/schemas/blog"
        },
        {
            "name": "products",
            "title": "Products",
            "count": 150,
            "schema": "/schemas/products"
        }
    ]
}
```

### Get Collection Objects

```http
GET /api/collections/{collection}
```

**Query Parameters:**
- `limit` - Number of results (default: 50, max: 100)
- `offset` - Starting position (default: 0)
- `sort` - Sort field (default: created)
- `order` - Sort direction: `asc` or `desc` (default: desc)
- `filter` - Filter by field values
- `search` - Full-text search

**Examples:**

```bash
# Get all blog posts
curl https://yoursite.com/api/collections/blog

# Get published posts only
curl "https://yoursite.com/api/collections/blog?filter[status]=published"

# Get latest 10 posts
curl "https://yoursite.com/api/collections/blog?limit=10&sort=date&order=desc"

# Search posts
curl "https://yoursite.com/api/collections/blog?search=tutorial"

# Pagination
curl "https://yoursite.com/api/collections/blog?limit=20&offset=40"
```

**Response:**
```json
{
    "collection": "blog",
    "total": 25,
    "count": 10,
    "limit": 10,
    "offset": 0,
    "objects": [
        {
            "id": "my-first-post",
            "title": "My First Post",
            "content": "Post content here...",
            "author": "john-doe",
            "status": "published",
            "date": "2024-01-15",
            "created": "2024-01-15T09:00:00Z",
            "modified": "2024-01-16T10:30:00Z"
        }
    ]
}
```

### Get Single Object

```http
GET /api/collections/{collection}/{id}
```

**Example:**
```bash
curl https://yoursite.com/api/collections/blog/my-first-post
```

**Response:**
```json
{
    "id": "my-first-post",
    "title": "My First Post",
    "content": "Post content here...",
    "author": "john-doe",
    "status": "published",
    "date": "2024-01-15",
    "tags": ["tutorial", "beginner"],
    "image": {
        "url": "/media/images/post-image.jpg",
        "alt": "Post featured image",
        "width": 1200,
        "height": 630
    },
    "created": "2024-01-15T09:00:00Z",
    "modified": "2024-01-16T10:30:00Z"
}
```

### Create Object

```http
POST /api/collections/{collection}
```

**Request Body:**
```json
{
    "title": "New Blog Post",
    "content": "This is the content of my new blog post.",
    "author": "jane-doe",
    "status": "draft",
    "tags": ["announcement", "news"]
}
```

**Response (201 Created):**
```json
{
    "id": "new-blog-post",
    "title": "New Blog Post",
    "content": "This is the content of my new blog post.",
    "author": "jane-doe",
    "status": "draft",
    "tags": ["announcement", "news"],
    "created": "2024-01-20T14:30:00Z",
    "modified": "2024-01-20T14:30:00Z"
}
```

### Update Object

```http
PUT /api/collections/{collection}/{id}
```

**Request Body:**
```json
{
    "title": "Updated Blog Post Title",
    "status": "published"
}
```

**Response (200 OK):**
```json
{
    "id": "new-blog-post",
    "title": "Updated Blog Post Title",
    "content": "This is the content of my new blog post.",
    "author": "jane-doe",
    "status": "published",
    "tags": ["announcement", "news"],
    "created": "2024-01-20T14:30:00Z",
    "modified": "2024-01-20T15:45:00Z"
}
```

### Partial Update

```http
PATCH /api/collections/{collection}/{id}
```

Updates only specified fields:

```json
{
    "status": "published"
}
```

### Delete Object

```http
DELETE /api/collections/{collection}/{id}
```

**Response (204 No Content)**

### Clone Object

```http
POST /api/collections/{collection}/{id}/clone
```

Creates a copy of an existing object. Optionally clone to a different ID or collection.

**Request Body (optional):**
```json
{
    "id": "new-object-id",
    "collection": "other-collection"
}
```

If no body is provided, the clone uses the same ID and collection as the source.

**Response (200 OK):**
```json
{
    "id": "new-object-id",
    "title": "My First Post",
    "content": "Post content here...",
    "created": "2024-01-20T14:30:00Z",
    "modified": "2024-01-20T14:30:00Z"
}
```

### Check Collection Exists

```http
HEAD /api/collections/{collection}
```

Returns `200 OK` if the collection exists, `404 Not Found` if it does not. No response body.

### Check Object Exists

```http
HEAD /api/collections/{collection}/{id}
```

Returns `200 OK` if the object exists, `404 Not Found` if it does not. No response body.

### Get Collection Schema

```http
GET /api/collections/{collection}/schema
```

Returns the schema definition for a specific collection.

**Query Parameters:**
- `raw` - Set to `true` to return the raw (non-flattened) schema. Default returns the flattened schema.

**Response (200 OK):**
```json
{
    "name": "blog",
    "title": "Blog Posts",
    "properties": {
        "title": {
            "type": "string",
            "required": true,
            "maxLength": 255
        }
    }
}
```

## Query API

The query endpoint provides paginated access to collection objects with filtering, sorting, and multiple output formats.

### Query Objects

```http
GET /api/collections/{collection}/query
```

**Query Parameters:**
- `limit` - Items per page
- `offset` - Pagination offset
- `sort` - Property name to sort by
- `search` - Full-text search term
- `include` - Comma-separated property names to include in results
- `exclude` - Comma-separated property names to exclude from results
- `format` - Output format: `json` (default), `csv`, `html`

**Example:**
```bash
# Paginated query with sorting
curl "https://yoursite.com/api/collections/blog/query?limit=10&offset=0&sort=date"

# Search with field filtering
curl "https://yoursite.com/api/collections/blog/query?search=tutorial&include=title,date,status"

# Export as CSV
curl "https://yoursite.com/api/collections/blog/query?format=csv&include=title,date"
```

**Response (JSON, default):**
```json
{
    "data": [
        {
            "id": "my-post",
            "title": "My Post",
            "date": "2024-01-15"
        }
    ],
    "pagination": {
        "total": 25,
        "count": 10,
        "per_page": 10,
        "current_page": 1,
        "total_pages": 3
    }
}
```

### Get Collection Index

```http
GET /api/collections/{collection}/index
```

Returns the full collection index. Supports filtering and sorting.

**Query Parameters:**
- `include` - Comma-separated property names to include
- `exclude` - Comma-separated property names to exclude
- `sort` - Property name to sort by

### Rebuild Collection Index

```http
PUT /api/collections/{collection}/index
```

Rebuilds the collection index from scratch. No request body required. Returns the rebuilt index.

## Property Operations

Update, patch, or delete individual properties on an object without sending the entire object.

### Update Property

```http
PUT /api/collections/{collection}/{id}/{property}
```

Replaces the entire property value (PUT semantics).

**Request Body:** The new value for the property.
```json
"New title value"
```

**Response (200 OK):** The updated object.

### Patch Property

```http
PATCH /api/collections/{collection}/{id}/{property}
```

Merges into the existing property value (PATCH semantics). Useful for updating individual fields within a complex property.

**Request Body:** Fields to merge.
```json
{
    "alt": "Updated alt text"
}
```

**Response (200 OK):** The updated object.

### Delete Property

```http
DELETE /api/collections/{collection}/{id}/{property}
```

Removes the property from the object entirely.

**Response (200 OK):** The updated object with the property removed.

### Increment Property

```http
POST /api/collections/{collection}/{id}/{property}/increment
POST /api/collections/{collection}/{id}/{property}/increment/{amount}
```

Increments a numeric property value. Defaults to incrementing by 1.

**Examples:**
```bash
# Increment view count by 1
curl -X POST https://yoursite.com/api/collections/blog/my-post/views/increment

# Increment by 5
curl -X POST https://yoursite.com/api/collections/blog/my-post/views/increment/5
```

**Response (200 OK):** The updated property value.

**Response (400 Bad Request):** If the property is not numeric.

### Decrement Property

```http
POST /api/collections/{collection}/{id}/{property}/decrement
POST /api/collections/{collection}/{id}/{property}/decrement/{amount}
```

Decrements a numeric property value. Defaults to decrementing by 1.

## Property Metadata

Update metadata associated with individual files or items within a property (e.g., alt text on an image, captions on gallery items).

### Update Property Metadata

```http
PUT /api/collections/{collection}/{id}/{property}/{name}
```

Replaces the metadata for a specific item within a property.

**Query Parameters:**
- `path` - Subfolder path for depot files

**Request Body:** The metadata to set.
```json
{
    "alt": "Photo of sunset",
    "caption": "Taken in Hawaii"
}
```

### Patch Property Metadata

```http
PATCH /api/collections/{collection}/{id}/{property}/{name}
```

Merges into existing metadata for a specific item within a property.

**Query Parameters:**
- `path` - Subfolder path for depot files

**Request Body:** Fields to merge into existing metadata.

## Deck Items API

CRUD operations for items within a deck (repeatable sections) property. Deck is a Pro edition feature.

### Create Deck Item

```http
POST /api/collections/{collection}/{id}/{property}/deck
```

**Request Body:**
```json
{
    "id": "item-1",
    "title": "Slide 1",
    "image": "/media/slide1.jpg",
    "caption": "Welcome slide"
}
```

The `id` field is optional if the schema supports auto-generation.

**Response (201 Created):** The updated parent object including the new deck item.

### Get Deck Item

```http
GET /api/collections/{collection}/{id}/{property}/deck/{itemId}
```

**Response (200 OK):**
```json
{
    "id": "item-1",
    "title": "Slide 1",
    "image": "/media/slide1.jpg",
    "caption": "Welcome slide"
}
```

**Response (404 Not Found):** If the deck item does not exist.

### Update Deck Item

```http
PUT /api/collections/{collection}/{id}/{property}/deck/{itemId}
```

Replaces the deck item entirely.

**Request Body:**
```json
{
    "title": "Updated Slide 1",
    "image": "/media/slide1-new.jpg",
    "caption": "Updated caption"
}
```

**Response (200 OK):** The updated parent object.

### Delete Deck Item

```http
DELETE /api/collections/{collection}/{id}/{property}/deck/{itemId}
```

**Response (200 OK):** The updated parent object with the deck item removed.

## Reports API

Export collection data as CSV or JSON with field selection.

### Get Report Fields

```http
GET /report/collections/{collection}/fields
```

Returns the list of fields available for export.

**Query Parameters:**
- `format` - `json` or `html` (default)

**Response (JSON):**
```json
[
    {
        "name": "title",
        "label": "Title",
        "type": "string"
    },
    {
        "name": "status",
        "label": "Status",
        "type": "string"
    }
]
```

### Export CSV Report

```http
GET /report/collections/{collection}/csv
```

**Query Parameters:**
- `fields` (required) - Comma-separated field names to export. Use dot notation for deck fields: `name,items.value,items.status`
- `include` - Filter objects by property values
- `exclude` - Exclude objects by property values

**Example:**
```bash
curl "https://yoursite.com/report/collections/blog/csv?fields=title,date,status"
```

**Response:** CSV file download with `Content-Disposition: attachment`.

Deck fields expand into multiple rows per object — one row per deck item, with scalar fields repeated on each row.

### Export JSON Report

```http
GET /report/collections/{collection}/json
```

**Query Parameters:**
- `fields` (required) - Comma-separated field names to export. Supports dot notation for deck fields.
- `include` - Filter objects by property values
- `exclude` - Exclude objects by property values

**Example:**
```bash
curl "https://yoursite.com/report/collections/blog/json?fields=title,date,status"
```

**Response:** JSON file download. Deck fields remain nested (not expanded like CSV).

```json
[
    {
        "title": "My Post",
        "date": "2024-01-15",
        "status": "published"
    }
]
```

If any objects are skipped due to data errors, the response wraps the data:
```json
{
    "data": [...],
    "errors": ["object-id-1", "object-id-2"]
}
```

## Schemas API

### Get All Schemas

```http
GET /api/schemas
```

**Response:**
```json
{
    "schemas": [
        {
            "name": "blog",
            "title": "Blog Posts",
            "description": "Blog post collection",
            "url": "/schemas/blog"
        }
    ]
}
```

### Get Schema Definition

```http
GET /api/schemas/{collection}
```

**Response:**
```json
{
    "name": "blog",
    "title": "Blog Posts",
    "description": "Blog post collection",
    "properties": {
        "title": {
            "type": "string",
            "required": true,
            "maxLength": 255
        },
        "content": {
            "type": "string",
            "format": "html"
        },
        "author": {
            "type": "string",
            "reference": "users"
        },
        "status": {
            "type": "string",
            "enum": ["draft", "published", "archived"],
            "default": "draft"
        },
        "tags": {
            "type": "array",
            "items": {
                "type": "string"
            }
        },
        "date": {
            "type": "string",
            "format": "date"
        }
    }
}
```

## File Downloads & Streaming

### Download File (Forces Download)

Download a file from a specific collection with `Content-Disposition: attachment`.

```http
GET /download/{collection}/{id}/{property}
POST /download/{collection}/{id}/{property}
```

**Path Parameters:**
- `collection` - Collection name (e.g., 'files', 'documents')
- `id` - Object ID
- `property` - Property name containing the file

**Query Parameters:**
- `pwd` - Encrypted password for protected files

**Examples:**

```bash
# Basic file download
curl -O https://yoursite.com/download/files/manual/file

# Download with custom collection/property
curl -O https://yoursite.com/download/documents/guide/pdf

# Password-protected file (password must be encrypted)
curl -O "https://yoursite.com/download/private/secret/file?pwd=ENCRYPTED_PASSWORD"
```

### Download Depot File

Download a specific file from a depot (multi-file) property.

```http
GET /download/{collection}/{id}/{property}/{filename}
POST /download/{collection}/{id}/{property}/{filename}
```

**Path Parameters:**
- `collection` - Collection name
- `id` - Object ID
- `property` - Depot property name
- `filename` - Specific file to download

**Query Parameters:**
- `path` - Subfolder path within depot
- `pwd` - Encrypted password for protected files

**Examples:**

```bash
# Download specific depot file
curl -O https://yoursite.com/download/depot/assets/files/document.pdf

# Download from subfolder
curl -O "https://yoursite.com/download/depot/assets/files/image.jpg?path=photos/vacation"

# Password-protected depot file
curl -O "https://yoursite.com/download/depot/private/files/secret.zip?pwd=ENCRYPTED_PASSWORD"
```

### Stream File (Plays in Browser)

Stream a file with `Content-Disposition: inline` and HTTP range request support. Ideal for video/audio files.

```http
GET /stream/{collection}/{id}/{property}
```

**Path Parameters:**
- `collection` - Collection name
- `id` - Object ID
- `property` - Property name containing the file

**Query Parameters:**
- `pwd` - Encrypted password for protected files

**Headers:**
- `Range` - HTTP range request (e.g., "bytes=0-1023")

**Response Headers:**
- `Accept-Ranges: bytes`
- `Content-Range` - For partial content responses (206)
- `Content-Length` - File or range size

**Examples:**

```bash
# Stream video file
curl https://yoursite.com/stream/videos/movie/video

# Range request for video seeking
curl -H "Range: bytes=0-1023" https://yoursite.com/stream/videos/movie/video

# Password-protected streaming
curl "https://yoursite.com/stream/private/secret/video?pwd=ENCRYPTED_PASSWORD"
```

### Stream Depot File

Stream a specific file from a depot property.

```http
GET /stream/{collection}/{id}/{property}/{filename}
```

**Path Parameters:**
- `collection` - Collection name
- `id` - Object ID
- `property` - Depot property name
- `filename` - Specific file to stream

**Query Parameters:**
- `path` - Subfolder path within depot
- `pwd` - Encrypted password for protected files

**Examples:**

```bash
# Stream depot video
curl https://yoursite.com/stream/media/playlist/videos/movie.mp4

# Stream with subfolder path
curl "https://yoursite.com/stream/media/playlist/videos/song.mp3?path=albums/rock"
```

### HTML5 Media Integration

**Video Streaming:**
```html
<video controls>
    <source src="/stream/videos/movie/video" type="video/mp4">
</video>
```

**Audio Streaming:**
```html
<audio controls>
    <source src="/stream/audio/song/file" type="audio/mpeg">
</audio>
```

### Download vs Stream Comparison

| Feature | Download | Stream |
|---------|----------|--------|
| Content-Disposition | attachment | inline |
| Browser Behavior | Forces download dialog | Plays/displays in browser |
| Range Requests | No | Yes (HTTP 206) |
| Video/Audio Support | Basic | Full seeking/scrubbing |
| Safari Compatibility | Standard | Enhanced for media |
| Use Cases | Documents, archives | Video, audio, PDFs |

### Password Protection

Both download and stream endpoints support password protection:

1. **Frontend**: Use Twig functions that auto-encrypt passwords
2. **API**: Passwords must be encrypted using the Cipher class
3. **URLs**: Encrypted passwords are URL-encoded in query parameters

**Twig Examples:**
```twig
{# Auto-encrypts plain password #}
{{ cms.download('id', {pwd: 'plaintext'}) }}
{{ cms.stream('id', {pwd: 'plaintext'}) }}

{# Already encrypted passwords work too #}
{{ cms.download('id', {pwd: encrypted_pwd}) }}
```

## Image Processing (ImageWorks)

### Basic Image Manipulation

```http
GET /imageworks/{collection}/{id}/{property}.{format}
```

**Parameters:**
- `w` - Width in pixels
- `h` - Height in pixels
- `fit` - Resize mode: `crop`, `contain`, `cover`, `fill`, `inside`, `outside`
- `format` - Output format: `jpg`, `png`, `webp`, `avif`
- `quality` - JPEG quality (1-100)
- `blur` - Blur amount (1-100)
- `brightness` - Brightness (-100 to 100)
- `contrast` - Contrast (-100 to 100)
- `gamma` - Gamma correction (0.1 to 3.0)
- `sharpen` - Sharpen amount (1-100)
- `grayscale` - Convert to grayscale (true/false)
- `sepia` - Apply sepia effect (true/false)

**Examples:**

```bash
# Resize to 800x600
curl "https://yoursite.com/imageworks/gallery/hero/image.jpg?w=800&h=600"

# Crop to square thumbnail
curl "https://yoursite.com/imageworks/products/laptop/image.jpg?w=300&h=300&fit=crop"

# Convert to WebP with quality
curl "https://yoursite.com/imageworks/blog/featured/image.webp?quality=80"

# Apply filters
curl "https://yoursite.com/imageworks/portfolio/photo/image.jpg?grayscale=true&contrast=20"

# Responsive image with blur
curl "https://yoursite.com/imageworks/hero/banner/image.jpg?w=1200&blur=5"
```

### Gallery Images

```http
GET /imageworks/{collection}/{id}/{property}/{name}.{format}
```

Fetch a specific image from a gallery property.

### Dynamic Gallery Images

```http
GET /imageworks/{collection}/{id}/{property}/{action}
```

**Actions:**
- `first` - Get the first image
- `last` - Get the last image
- `random` - Get a random image
- `featured` - Get the featured image

## Error Handling

### Error Response Format

```json
{
    "error": {
        "code": 400,
        "message": "Validation failed",
        "details": {
            "title": ["Title is required"],
            "email": ["Invalid email format"]
        }
    }
}
```

### HTTP Status Codes

- `200 OK` - Successful request
- `201 Created` - Resource created successfully
- `204 No Content` - Successful request with no response body
- `400 Bad Request` - Invalid request data
- `401 Unauthorized` - Authentication required
- `403 Forbidden` - Insufficient permissions
- `404 Not Found` - Resource not found
- `422 Unprocessable Entity` - Validation errors
- `429 Too Many Requests` - Rate limit exceeded
- `500 Internal Server Error` - Server error

## Rate Limiting

**Headers:**
- `X-RateLimit-Limit` - Request limit per window
- `X-RateLimit-Remaining` - Remaining requests
- `X-RateLimit-Reset` - Time when limit resets

**Example Response Headers:**
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 45
X-RateLimit-Reset: 1705751400
```

## Pagination

For endpoints that return multiple items:

**Headers:**
- `X-Total-Count` - Total number of items
- `Link` - Pagination links (next, prev, first, last)

**Example:**
```
X-Total-Count: 150
Link: </collections/blog?offset=20&limit=20>; rel="next",
      </collections/blog?offset=0&limit=20>; rel="first",
      </collections/blog?offset=140&limit=20>; rel="last"
```

## CORS Support

The API supports Cross-Origin Resource Sharing (CORS) for browser-based requests:

```javascript
// Example browser request
fetch('https://yoursite.com/api/collections/blog', {
    method: 'GET',
    headers: {
        'X-API-Key': 'tcms_your_api_key_here',
        'Content-Type': 'application/json'
    }
});
```
