# Public Collections Guide

## Overview

Total CMS allows you to make collection operations publicly accessible without authentication. This enables:

- Public-facing forms (contact, signup, newsletter)
- Public APIs for reading data (blog posts, products, directories)
- Public commenting systems (using deck properties)
- Public content management (user-generated content)

## How It Works

Public operations use the standard collection API endpoints with special handling:

1. Collection must have `publicOperations` array in its metadata
2. `DualAuthMiddleware` detects allowed operations and bypasses authentication
3. `BaseAccessMiddleware` and its subclasses bypass access control checks
4. CSRF protection still applies for state-changing operations (create, update, delete)
5. All submitted data is validated against the collection's schema

## Configuration

Enable public access by adding a `publicOperations` array to your collection's `.meta.json` file:

```json
{
  "id": "blog-posts",
  "label": "Blog Posts",
  "type": "blog",
  "publicOperations": ["read"]
}
```

### Available Operations

- **`create`** - Create new objects (`POST /collections/{collection}`, clone operations)
- **`read`** - Read objects and lists (`GET /collections/{collection}`, `GET /collections/{collection}/{id}`)
- **`update`** - Update objects and properties (PUT/PATCH operations, file uploads, deck management)
- **`delete`** - Delete entire objects (`DELETE /collections/{collection}/{id}`)

**Special Case - HEAD Requests:**

`HEAD /collections/{collection}/{id}` (object existence checking) is **always allowed publicly** regardless of `publicOperations` settings. This enables ID validation on signup forms without exposing data:

```javascript
// Check if username is available
const response = await fetch('/collections/users/john-doe', {method: 'HEAD'});
if (response.status === 200) {
  alert('Username already taken');
} else if (response.status === 404) {
  alert('Username available!');
}
```

HEAD requests return only status codes (200 = exists, 404 = doesn't exist) without exposing object data. This is safe for public access even on private collections.

**Security Note**: Operations not listed in `publicOperations` require authentication. Use schema validation with `additionalProperties: false` to control which fields can be modified.

## Creating a Public Form

### HTML Form Example

```html
<form action="/collections/member-signup" method="POST">
  <!-- CSRF token required -->
  <input type="hidden" name="csrf_token" value="{{ csrf_token }}">

  <div>
    <label for="firstName">First Name:</label>
    <input type="text" id="firstName" name="firstName" required>
  </div>

  <div>
    <label for="lastName">Last Name:</label>
    <input type="text" id="lastName" name="lastName" required>
  </div>

  <div>
    <label for="email">Email:</label>
    <input type="email" id="email" name="email" required>
  </div>

  <div>
    <label for="phone">Phone:</label>
    <input type="tel" id="phone" name="phone">
  </div>

  <button type="submit">Sign Up</button>
</form>
```

### Twig Template Example

```twig
<form action="/collections/contact-form" method="POST">
  {# CSRF token automatically included by TotalForm #}
  {{ cms.csrfField() }}

  <div>
    <label for="name">Name:</label>
    <input type="text" id="name" name="name" required>
  </div>

  <div>
    <label for="email">Email:</label>
    <input type="email" id="email" name="email" required>
  </div>

  <div>
    <label for="message">Message:</label>
    <textarea id="message" name="message" rows="5" required></textarea>
  </div>

  <button type="submit">Send Message</button>
</form>
```

### JavaScript/AJAX Example

```javascript
const form = document.querySelector('form');

form.addEventListener('submit', async (e) => {
  e.preventDefault();

  const formData = new FormData(form);
  const data = Object.fromEntries(formData);

  try {
    const response = await fetch('/collections/member-signup', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': data.csrf_token
      },
      body: JSON.stringify(data)
    });

    if (response.ok) {
      const result = await response.json();
      console.log('Submission successful:', result);
      form.reset();
    } else {
      const error = await response.json();
      console.error('Submission failed:', error);
    }
  } catch (err) {
    console.error('Network error:', err);
  }
});
```

## Security Considerations

### CSRF Protection

CSRF protection is **always enabled** for public forms. Every submission must include a valid CSRF token:

- HTML forms: Include `{{ csrf_token }}` in a hidden input field
- AJAX requests: Include token in `X-CSRF-Token` header or request body
- Sessions are created for all users (authenticated and public)

### Schema Validation

All submissions (public and authenticated) are validated against the collection's JSON schema. Use your schema to:

- Define required fields
- Validate field types (string, number, boolean, etc.)
- Set format validation (email, URL, date, etc.)
- Define allowed values with enums
- Set default values for fields not submitted

**Example: Secure Contact Form Schema**
```json
{
  "type": "object",
  "properties": {
    "name": {
      "type": "string",
      "minLength": 1
    },
    "email": {
      "type": "string",
      "format": "email"
    },
    "message": {
      "type": "string",
      "minLength": 10
    },
    "status": {
      "type": "string",
      "default": "new"
    },
    "approved": {
      "type": "boolean",
      "default": false
    }
  },
  "required": ["name", "email", "message"],
  "additionalProperties": false
}
```

**Security benefits**:
- `additionalProperties: false` - Rejects unexpected fields
- `default` values - Ensures safe defaults for sensitive fields
- `required` - Validates required data is present
- `format` - Validates data format (email, URL, etc.)

### Rate Limiting

**Note**: Rate limiting is not currently implemented. Consider adding rate limiting middleware for production environments to prevent abuse.

## Common Use Cases

### Public Read Only (Blog, Products, Directory)

```json
{
  "id": "blog-posts",
  "label": "Blog Posts",
  "type": "blog",
  "publicOperations": ["read"]
}
```

**Use case**: Public can read all blog posts via API without authentication, but only authenticated users can create/edit/delete.

### Contact Form (Create Only)

```json
{
  "id": "contact-submissions",
  "label": "Contact Submissions",
  "type": "custom",
  "publicOperations": ["create"],
  "schema": {
    "type": "object",
    "properties": {
      "name": {"type": "string"},
      "email": {"type": "string", "format": "email"},
      "subject": {"type": "string"},
      "message": {"type": "string"},
      "status": {"type": "string", "default": "new"},
      "created": {"type": "string", "format": "date-time"}
    },
    "required": ["name", "email", "message"],
    "additionalProperties": false
  }
}
```

**Benefits**:
- Public can submit contact forms
- Schema prevents unexpected fields
- Default values ensure safe initial state
- Admins can view/manage via admin interface

### Member Signup with ID Validation (Create Only + HEAD)

```json
{
  "id": "members",
  "label": "Members",
  "type": "custom",
  "publicOperations": ["create"],
  "schema": {
    "type": "object",
    "properties": {
      "id": {"type": "string"},
      "firstName": {"type": "string"},
      "lastName": {"type": "string"},
      "email": {"type": "string", "format": "email"},
      "phone": {"type": "string"},
      "bio": {"type": "string"},
      "approved": {"type": "boolean", "default": false},
      "role": {"type": "string", "default": "member"}
    },
    "required": ["id", "firstName", "lastName", "email"],
    "additionalProperties": false
  }
}
```

**Use case**: Public signup form where users pick their own username/ID. The form can validate ID availability using HEAD requests without exposing the member directory:

```javascript
// Check username availability as user types
async function checkUsername(username) {
  const response = await fetch(`/collections/members/${username}`, {
    method: 'HEAD'
  });

  if (response.status === 200) {
    return 'Username already taken';
  } else if (response.status === 404) {
    return 'Username available';
  }
}
```

**Benefits**:
- Public can create accounts
- Can validate ID/username availability via HEAD
- Member data remains private (no `read` permission)
- Admin approves new members via `approved` flag

### Blog Commenting System (Full CRUD)

```json
{
  "id": "blog-posts",
  "label": "Blog Posts",
  "type": "blog",
  "publicOperations": ["create", "read", "update", "delete"],
  "properties": {
    "title": {"label": "Title", "field": "text"},
    "content": {"label": "Content", "field": "markdown"},
    "comments": {"label": "Comments", "field": "deck"}
  }
}
```

**Use case**: Comments stored in a deck property. Public users can:
- **Read**: View all posts and comments
- **Create**: Add comments via `POST /collections/blog-posts/{post-id}/comments/deck`
- **Update**: Edit their own comments via `PUT /collections/blog-posts/{post-id}/comments/deck/{comment-id}`
- **Delete**: Remove their own comments via `DELETE /collections/blog-posts/{post-id}/comments/deck/{comment-id}`

**Note**: This requires application-level logic to ensure users can only edit/delete their own comments (e.g., checking a user identifier stored in the comment).

### Newsletter Subscription (Create Only)

```json
{
  "id": "newsletter-subscribers",
  "label": "Newsletter Subscribers",
  "type": "custom",
  "publicOperations": ["create"],
  "schema": {
    "type": "object",
    "properties": {
      "email": {"type": "string", "format": "email"},
      "name": {"type": "string"},
      "subscribed": {"type": "boolean", "default": true},
      "subscribedDate": {"type": "string", "format": "date-time"}
    },
    "required": ["email"],
    "additionalProperties": false
  }
}
```

## Implementation Details

### Middleware Flow

1. **SessionStartMiddleware**: Starts session for all users (public and authenticated)
2. **CSRFProtectionMiddleware**: Validates CSRF token for state-changing requests (POST, PUT, PATCH, DELETE)
3. **DualAuthMiddleware**:
   - Detects operation type based on route name (create, read, update, delete)
   - Checks if operation is in collection's `publicOperations` array
   - If yes, sets `publicSubmission` attribute and bypasses authentication
   - If no, requires authentication (session or API key)
4. **BaseAccessMiddleware**:
   - Checks for `publicSubmission` attribute
   - If present, bypasses access control checks
   - If not, enforces access group permissions
5. **Action**:
   - Executes the requested operation
   - Schema validation automatically enforces allowed fields and data types

### Operation Detection

Operations are detected by route name, not HTTP method:

```php
// Create operations
'object-save', 'object-clone'

// Read operations
'collection-fetch-index', 'object-fetch', 'object-exists', 'deck-item-fetch'

// Update operations
'object-update', 'object-patch', 'property-update', 'deck-item-create',
'property-file-save', 'property-file-delete', etc.

// Delete operations
'object-delete'
```

This allows semantic grouping (e.g., `DELETE /property-file-delete` is an "update" operation).

### Public Submission Attribute

The `publicSubmission` request attribute is set by `DualAuthMiddleware` and used by downstream middleware:

```php
// DualAuthMiddleware detects operation and checks publicOperations
$operation = $this->detectOperation($request); // 'create', 'read', 'update', 'delete'
$publicOps = $collection->publicOperations ?? [];

if (in_array($operation, $publicOps)) {
    $request = $request->withAttribute('publicSubmission', true);
    return $handler->handle($request);
}

// BaseAccessMiddleware checks attribute
if ($request->getAttribute('publicSubmission') === true) {
    return $handler->handle($request); // Bypass access control
}
```

## Testing Public Collections

### Manual Testing

**Testing Public Create:**
1. Create a test collection with `"publicOperations": ["create"]`
2. Define a JSON schema with `additionalProperties: false` for security
3. Open the form in an incognito/private browser window (no authentication)
4. Submit the form with valid data
5. Verify the object is created in the collection

**Testing Public Read:**
1. Create a collection with `"publicOperations": ["read"]`
2. Add some test objects
3. Open an incognito/private browser window
4. Request `GET /collections/{collection}` - should return objects
5. Try `POST /collections/{collection}` - should require authentication

**Testing Public Update/Delete:**
1. Create a collection with appropriate `publicOperations`
2. Use API client (Postman, curl) to test without authentication
3. Verify operations work for public users
4. Verify non-public operations still require authentication

### Automated Testing

```php
// Test public create operation
public function testPublicCreate(): void
{
    $data = ['name' => 'Test', 'email' => 'test@example.com'];
    $response = $this->postJson('/collections/contact-form', $data);
    $this->assertSame(200, $response->getStatusCode());
}

// Test public read operation
public function testPublicRead(): void
{
    $response = $this->get('/collections/blog-posts');
    $this->assertSame(200, $response->getStatusCode());
}

// Test that update requires auth when not in publicOperations
public function testUpdateRequiresAuth(): void
{
    $response = $this->putJson('/collections/blog-posts/test-post', ['title' => 'Updated']);
    $this->assertIn($response->getStatusCode(), [401, 403]);
}
```

## Best Practices

1. **Use JSON schema validation** to enforce data types and formats
2. **Set `additionalProperties: false`** in schema to prevent unexpected fields
3. **Set secure defaults** for sensitive fields (e.g., `approved: false`, `role: 'member'`)
4. **Be cautious with `update` and `delete` operations** - ensure application logic prevents unauthorized modifications
5. **Implement rate limiting** in production to prevent abuse (especially for `create` operations)
6. **Monitor public submissions** for spam or malicious content
7. **Use CSRF protection** (automatically enabled for state-changing operations)
8. **Test in incognito mode** to verify public access works correctly
9. **Use `read` sparingly** if you have sensitive data - consider creating separate public/private collections
10. **Document your security model** when using public `update`/`delete` (e.g., how you prevent users from modifying others' content)

## Troubleshooting

### "Authentication required" error

- Verify `publicOperations` array is in collection's `.meta.json`
- Check that the operation you're trying (`create`, `read`, `update`, `delete`) is in the array
- Ensure operation names are lowercase
- Check that collection ID in the route matches metadata file
- Clear cache if you recently modified `publicOperations`

### Fields not being saved

- Check JSON schema allows those fields
- Verify `additionalProperties: false` is not blocking expected fields
- Verify field names match exactly (case-sensitive)

### CSRF token errors

- Ensure form includes CSRF token field
- Verify sessions are working (SessionStartMiddleware is enabled)
- Check that token hasn't expired (refresh the page)

### Invalid data format

- Check JSON schema validation requirements
- Verify field types match schema (string, number, boolean, etc.)
- Check required fields are included in submission

## Future Enhancements

Potential features for future versions:

- Built-in rate limiting for public submissions
- Honeypot fields for spam prevention
- reCAPTCHA integration option
- Email notifications on public submissions
- Success/error page redirects
- Custom validation rules for public forms
