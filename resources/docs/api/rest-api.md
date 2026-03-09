---
title: "REST API Documentation"
description: "Complete Total CMS REST API reference for CRUD operations on collections, objects, images, files, and schemas with authentication and error handling."
---

# REST API Documentation

Total CMS provides a RESTful API for accessing content and schemas. This documentation covers all available endpoints with examples and response formats.

## API Overview

- **Base URL**: Your site's root URL (e.g., `https://yoursite.com`)
- **Content Type**: `application/json`
- **Authentication**: API keys (Pro edition) or session-based

## Authentication

Total CMS supports two authentication methods for API access: **API Keys** (recommended for external applications, Pro edition required) and **Session Authentication** (for same-origin admin panel requests).

> **📖 For comprehensive API key documentation, see [API Keys Guide](docs/api/api-keys)**

### API Key Authentication (Pro Edition)

API keys provide secure, token-based authentication ideal for headless CMS implementations, mobile apps, and third-party integrations.

**Using the X-API-Key header (recommended):**
```bash
curl -H "X-API-Key: tcms_1234567890abcdef1234567890abcdef" \
     -H "Content-Type: application/json" \
     https://yoursite.com/collections/blog
```

**Using query parameter:**
```bash
curl "https://yoursite.com/collections/blog?api_key=tcms_1234567890abcdef1234567890abcdef"
```

**Key Features:**
- **Scope-based permissions** - Control HTTP methods (GET, POST, PUT, DELETE, PATCH)
- **Path restrictions** - Limit access to specific collections or endpoints
- **Usage tracking** - Monitor last used timestamps
- **Easy revocation** - Delete keys to immediately revoke access

**Creating API Keys:**
Navigate to **Utilities** → **API Keys** in the admin interface, or visit `/admin/utils/api-keys`.

For detailed information on scopes, permissions, and best practices, see the [API Keys documentation](docs/api/api-keys).

### Session Authentication

For admin panel and same-origin requests using cookies:

```javascript
// Include CSRF token for session-based requests
fetch('/collections/blog', {
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
GET /collections
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
GET /collections/{collection}
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
curl https://yoursite.com/collections/blog

# Get published posts only
curl "https://yoursite.com/collections/blog?filter[status]=published"

# Get latest 10 posts
curl "https://yoursite.com/collections/blog?limit=10&sort=date&order=desc"

# Search posts
curl "https://yoursite.com/collections/blog?search=tutorial"

# Pagination
curl "https://yoursite.com/collections/blog?limit=20&offset=40"
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
GET /collections/{collection}/{id}
```

**Example:**
```bash
curl https://yoursite.com/collections/blog/my-first-post
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
POST /collections/{collection}
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
PUT /collections/{collection}/{id}
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
PATCH /collections/{collection}/{id}
```

Updates only specified fields:

```json
{
    "status": "published"
}
```

### Delete Object

```http
DELETE /collections/{collection}/{id}
```

**Response (204 No Content)**

## Schemas API

### Get All Schemas

```http
GET /schemas
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
GET /schemas/{collection}
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
fetch('https://yoursite.com/collections/blog', {
    method: 'GET',
    headers: {
        'X-API-Key': 'tcms_your_api_key_here',
        'Content-Type': 'application/json'
    }
});
```
