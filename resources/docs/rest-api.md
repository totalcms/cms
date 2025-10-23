# REST API Documentation

Total CMS provides a comprehensive RESTful API for managing content, users, and system resources. This documentation covers all available endpoints with examples and response formats.

## API Overview

<!-- Need to dynamically add the API URL for this installation -->

- **Base URL**: `/api`
- **Content Type**: `application/json`
- **Authentication**: API keys or session-based
- **Rate Limiting**: 60 requests per minute (configurable)

## Authentication

Total CMS supports two authentication methods for API access: **API Keys** (recommended for external applications) and **Session Authentication** (for same-origin admin panel requests).

> **📖 For comprehensive API key documentation, see [API Keys Guide](api-keys.md)**

### API Key Authentication (Recommended)

API keys provide secure, token-based authentication ideal for headless CMS implementations, mobile apps, and third-party integrations.

**Using the X-API-Key header (recommended):**
```bash
curl -H "X-API-Key: tcms_1234567890abcdef1234567890abcdef" \
     -H "Content-Type: application/json" \
     https://yoursite.com/api/blog
```

**Using query parameter:**
```bash
curl "https://yoursite.com/api/blog?api_key=tcms_1234567890abcdef1234567890abcdef"
```

**Key Features:**
- **Scope-based permissions** - Control HTTP methods (GET, POST, PUT, DELETE, PATCH)
- **Path restrictions** - Limit access to specific collections or endpoints
- **Usage tracking** - Monitor last used timestamps
- **Easy revocation** - Delete keys to immediately revoke access

**Creating API Keys:**
Navigate to **Utilities** → **API Keys** in the admin interface, or visit `/admin/utils/api-keys`.

For detailed information on scopes, permissions, and best practices, see the [API Keys documentation](api-keys.md).

### Session Authentication

For admin panel and same-origin requests using cookies:

```javascript
// Include CSRF token for session-based requests
fetch('/api/blog', {
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

## File Downloads & Streaming API

### Download File (Forces Download)

Download a file from a specific collection with `Content-Disposition: attachment`.

```http
GET /api/download/{collection}/{id}/{property}
POST /api/download/{collection}/{id}/{property}
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
curl -O https://yoursite.com/api/download/files/manual/file

# Download with custom collection/property
curl -O https://yoursite.com/api/download/documents/guide/pdf

# Password-protected file (password must be encrypted)
curl -O "https://yoursite.com/api/download/private/secret/file?pwd=ENCRYPTED_PASSWORD"
```

### Download Depot File

Download a specific file from a depot (multi-file) property.

```http
GET /api/download/{collection}/{id}/{property}/{filename}
POST /api/download/{collection}/{id}/{property}/{filename}
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
curl -O https://yoursite.com/api/download/depot/assets/files/document.pdf

# Download from subfolder
curl -O "https://yoursite.com/api/download/depot/assets/files/image.jpg?path=photos/vacation"

# Password-protected depot file
curl -O "https://yoursite.com/api/download/depot/private/files/secret.zip?pwd=ENCRYPTED_PASSWORD"
```

### Stream File (Plays in Browser)

Stream a file with `Content-Disposition: inline` and HTTP range request support. Ideal for video/audio files.

```http
GET /api/stream/{collection}/{id}/{property}
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
curl https://yoursite.com/api/stream/videos/movie/video

# Range request for video seeking
curl -H "Range: bytes=0-1023" https://yoursite.com/api/stream/videos/movie/video

# Password-protected streaming
curl "https://yoursite.com/api/stream/private/secret/video?pwd=ENCRYPTED_PASSWORD"
```

### Stream Depot File

Stream a specific file from a depot property.

```http
GET /api/stream/{collection}/{id}/{property}/{filename}
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
curl https://yoursite.com/api/stream/media/playlist/videos/movie.mp4

# Stream with subfolder path
curl "https://yoursite.com/api/stream/media/playlist/videos/song.mp3?path=albums/rock"
```

### HTML5 Media Integration

**Video Streaming:**
```html
<video controls>
    <source src="/api/stream/videos/movie/video" type="video/mp4">
</video>
```

**Audio Streaming:**
```html
<audio controls>
    <source src="/api/stream/audio/song/file" type="audio/mpeg">
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

For more examples and advanced usage, see the [PHP API Documentation](docs/php-api).