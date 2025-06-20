# REST API Documentation

Total CMS provides a comprehensive RESTful API for managing content, users, and system resources. This documentation covers all available endpoints with examples and response formats.

## API Overview

<!-- Need to dynamically add the API URL for this installation -->

- **Base URL**: `/api`
- **Content Type**: `application/json`
- **Authentication**: Bearer token or session-based
- **Rate Limiting**: 60 requests per minute (configurable)

## Authentication

### API Token Authentication

```bash
# Using Authorization header
curl -H "Authorization: Bearer YOUR_API_TOKEN" \
     -H "Content-Type: application/json" \
     https://yoursite.com/api/blog
```

### Session Authentication

For admin panel and same-origin requests:

```javascript
// Include CSRF token for session-based requests
fetch('/api/blog', {
    headers: {
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
        'Content-Type': 'application/json'
    }
});
```

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
            "schema": "/api/schemas/blog"
        },
        {
            "name": "products",
            "title": "Products",
            "count": 150,
            "schema": "/api/schemas/products"
        }
    ]
}
```

### Get Collection Objects

```http
GET /api/{collection}
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
curl https://yoursite.com/api/blog

# Get published posts only
curl "https://yoursite.com/api/blog?filter[status]=published"

# Get latest 10 posts
curl "https://yoursite.com/api/blog?limit=10&sort=date&order=desc"

# Search posts
curl "https://yoursite.com/api/blog?search=tutorial"

# Pagination
curl "https://yoursite.com/api/blog?limit=20&offset=40"
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
GET /api/{collection}/{id}
```

**Example:**
```bash
curl https://yoursite.com/api/blog/my-first-post
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
POST /api/{collection}
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
PUT /api/{collection}/{id}
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
PATCH /api/{collection}/{id}
```

Updates only specified fields:

```json
{
    "status": "published"
}
```

### Delete Object

```http
DELETE /api/{collection}/{id}
```

**Response (204 No Content)**

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
            "url": "/api/schemas/blog"
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

## Image Processing API (ImageWorks)

### Basic Image Manipulation

```http
GET /api/image/{collection}/{id}?{parameters}
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
curl "https://yoursite.com/api/image/gallery/hero?w=800&h=600"

# Crop to square thumbnail
curl "https://yoursite.com/api/image/products/laptop?w=300&h=300&fit=crop"

# Convert to WebP with quality
curl "https://yoursite.com/api/image/blog/featured?format=webp&quality=80"

# Apply filters
curl "https://yoursite.com/api/image/portfolio/photo?grayscale=true&contrast=20"

# Responsive image with blur
curl "https://yoursite.com/api/image/hero/banner?w=1200&blur=5"
```

### Watermark Application

```http
GET /api/image/{collection}/{id}?watermark={watermark_id}&position={position}&opacity={opacity}
```

**Parameters:**
- `watermark` - ID of watermark image
- `position` - Position: `topleft`, `topright`, `bottomleft`, `bottomright`, `center`
- `opacity` - Watermark opacity (0-100)

**Example:**
```bash
curl "https://yoursite.com/api/image/photos/landscape?watermark=logo&position=bottomright&opacity=50"
```

### Image Information

```http
GET /api/image/{collection}/{id}/info
```

**Response:**
```json
{
    "width": 1920,
    "height": 1080,
    "format": "jpeg",
    "size": 245760,
    "density": 72,
    "hasAlpha": false,
    "colorspace": "srgb",
    "exif": {
        "camera": "Canon EOS R5",
        "lens": "RF 24-70mm F2.8 L IS USM",
        "exposureTime": "1/125",
        "fNumber": "f/5.6",
        "iso": 400,
        "dateTime": "2024-01-15 14:30:00"
    }
}
```

## File Downloads API

### Download File

```http
GET /api/download/{collection}/{id}
```

**Query Parameters:**
- `filename` - Custom download filename
- `inline` - Display in browser instead of download (true/false)

**Examples:**

```bash
# Force download
curl -O https://yoursite.com/api/download/files/manual

# Custom filename
curl -O "https://yoursite.com/api/download/files/manual?filename=user-guide.pdf"

# Display in browser
curl "https://yoursite.com/api/download/files/manual?inline=true"
```

### Bulk Download

```http
POST /api/download/bulk
```

**Request Body:**
```json
{
    "items": [
        {"collection": "files", "id": "manual"},
        {"collection": "files", "id": "quick-start"},
        {"collection": "images", "id": "logo"}
    ],
    "format": "zip",
    "filename": "resources.zip"
}
```

### Protected Downloads

For protected files, include authentication:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://yoursite.com/api/download/private/confidential
```

## Import/Export API

### Export Collection

```http
GET /api/export/{collection}
```

**Query Parameters:**
- `format` - Export format: `json`, `csv`, `xml`
- `include_media` - Include media files (true/false)
- `filter` - Filter criteria
- `fields` - Specific fields to export

**Examples:**

```bash
# Export as JSON
curl https://yoursite.com/api/export/blog?format=json

# Export as CSV with specific fields
curl "https://yoursite.com/api/export/products?format=csv&fields=name,price,sku"

# Export published posts only
curl "https://yoursite.com/api/export/blog?filter[status]=published"

# Export with media files
curl "https://yoursite.com/api/export/gallery?include_media=true"
```

### Import Data

```http
POST /api/import/{collection}
```

**Request Body (JSON):**
```json
{
    "format": "json",
    "data": [
        {
            "title": "Imported Post",
            "content": "This post was imported via API",
            "status": "draft"
        }
    ],
    "options": {
        "update_existing": true,
        "skip_validation": false
    }
}
```

**Request Body (CSV):**
```http
Content-Type: text/csv

title,content,status
"First Post","Content here","published"
"Second Post","More content","draft"
```

**Response:**
```json
{
    "imported": 25,
    "updated": 5,
    "skipped": 2,
    "errors": [
        {
            "row": 3,
            "error": "Missing required field: title"
        }
    ]
}
```

## Search API

### Full-Text Search

```http
GET /api/search?q={query}&collections={collections}&limit={limit}
```

**Query Parameters:**
- `q` - Search query
- `collections` - Comma-separated list of collections to search
- `limit` - Maximum results (default: 50)
- `offset` - Starting position
- `highlight` - Include search term highlighting (true/false)

**Examples:**

```bash
# Search across all collections
curl "https://yoursite.com/api/search?q=tutorial"

# Search specific collections
curl "https://yoursite.com/api/search?q=product&collections=blog,products"

# Search with highlighting
curl "https://yoursite.com/api/search?q=guide&highlight=true"
```

**Response:**
```json
{
    "query": "tutorial",
    "total": 15,
    "results": [
        {
            "collection": "blog",
            "id": "css-tutorial",
            "title": "CSS Tutorial for Beginners",
            "excerpt": "Learn CSS basics in this comprehensive tutorial...",
            "score": 0.95,
            "highlights": {
                "title": ["CSS <mark>Tutorial</mark> for Beginners"],
                "content": ["Learn CSS basics in this comprehensive <mark>tutorial</mark>..."]
            }
        }
    ]
}
```

## User Management API

### Get Current User

```http
GET /api/user/me
```

**Response:**
```json
{
    "id": "john-doe",
    "username": "johndoe",
    "email": "john@example.com",
    "name": "John Doe",
    "role": "admin",
    "permissions": ["read", "write", "delete"],
    "lastLogin": "2024-01-20T08:30:00Z"
}
```

### Update User Profile

```http
PUT /api/user/me
```

**Request Body:**
```json
{
    "name": "John Smith",
    "email": "johnsmith@example.com"
}
```

## System API

### System Information

```http
GET /api/system/info
```

**Response:**
```json
{
    "version": "3.0.0",
    "php_version": "8.2.15",
    "environment": "production",
    "storage": {
        "total_objects": 1250,
        "total_collections": 8,
        "disk_usage": "2.5GB"
    },
    "license": {
        "status": "active",
        "expires": "2024-12-31"
    }
}
```

### Health Check

```http
GET /api/health
```

**Response:**
```json
{
    "status": "healthy",
    "timestamp": "2024-01-20T10:30:00Z",
    "checks": {
        "database": "ok",
        "storage": "ok",
        "cache": "ok",
        "license": "ok"
    }
}
```

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
Link: </api/blog?offset=20&limit=20>; rel="next",
      </api/blog?offset=0&limit=20>; rel="first",
      </api/blog?offset=140&limit=20>; rel="last"
```

## CORS Support

The API supports Cross-Origin Resource Sharing (CORS) for browser-based requests:

```javascript
// Example browser request
fetch('https://yoursite.com/api/blog', {
    method: 'GET',
    headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json'
    }
});
```

## Webhook Support

Configure webhooks for real-time notifications:

```http
POST /api/webhooks
```

**Request Body:**
```json
{
    "url": "https://your-app.com/webhook",
    "events": ["object.created", "object.updated", "object.deleted"],
    "collections": ["blog", "products"],
    "secret": "your-webhook-secret"
}
```

For complete API documentation with interactive examples, visit the Swagger UI at `/admin/docs/api`.

## SDK and Libraries

- **JavaScript**: Official JS SDK available
- **PHP**: Native integration
- **Python**: Community SDK
- **cURL**: Works with any language supporting HTTP

For more examples and advanced usage, see the [PHP API Documentation](php-api.md).