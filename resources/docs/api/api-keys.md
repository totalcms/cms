---
title: "API Keys"
description: "Create and manage token-based API keys for Total CMS REST API authentication with scoped method and path permissions and usage tracking."
---

# API Keys

API keys provide secure, token-based authentication for accessing Total CMS's REST API without requiring user session authentication. They are ideal for headless CMS implementations, mobile applications, third-party integrations, and automated workflows.

## When to Use API Keys

API keys are recommended for:

- **Headless CMS Applications**: Frontend applications that consume content via the REST API
- **Mobile Applications**: iOS, Android, or cross-platform apps accessing CMS content
- **Third-Party Integrations**: External services or platforms integrating with Total CMS
- **Automated Workflows**: Scripts, cron jobs, or CI/CD pipelines that need API access
- **Microservices**: Distributed architectures where services communicate via APIs

For traditional web applications with user login, session-based authentication is typically more appropriate.

## Creating and Managing API Keys

### Accessing API Key Management

Navigate to **Utilities** → **API Keys** in the admin interface, or visit `/admin/utils/api-keys` directly.

### Creating a New API Key

1. Click **"Create New API Key"**
2. Enter a descriptive **Name** (e.g., "Mobile App", "Blog Integration", "Analytics Service")
3. Configure **Scopes** (HTTP methods and paths)
4. Click **"Create API Key"**
5. **Copy the generated key immediately** - it will only be displayed once

### Viewing Existing Keys

The API Keys page displays:
- **Name**: Descriptive identifier for the key
- **Masked Key**: Partial key display (e.g., `tcms_****...****1234`) for security
- **Last Used**: Timestamp of most recent API request with this key
- **Actions**: Delete button for revoking access

### Deleting API Keys

Click the **Delete** button next to any key to permanently revoke access. This action cannot be undone.

## Permission Scopes

API keys use a scope-based permission system with two dimensions: HTTP methods and path restrictions.

### HTTP Methods

Control which HTTP operations the API key can perform:

- **GET**: Read-only access (view collections, objects, schemas)
- **POST**: Create new resources (add objects, collections)
- **PUT**: Update existing resources (modify objects, collections)
- **DELETE**: Remove resources (delete objects, collections)
- **PATCH**: Partial updates to resources

**Examples:**
- Read-only key: `GET` only
- Content editor key: `GET`, `POST`, `PUT`
- Full access key: `GET`, `POST`, `PUT`, `DELETE`, `PATCH`

### Path Restrictions

Limit which API endpoints the key can access using path prefixes.

#### Universal Access

Use `*` to allow access to all API endpoints:

```
Allowed Paths: *
```

This key can access any API route.

#### Specific Collections

Restrict access to specific collections:

```
Allowed Paths: /collections/blog
```

This key can access:
- `/collections/blog` (list blog objects)
- `/collections/blog/123` (view/edit specific blog object)
- `/collections/blog/new` (create new blog object)

#### Multiple Paths

Add multiple path restrictions by clicking **"Add Path"**:

```
Allowed Paths:
  /collections/blog
  /collections/news
  /collections/events
```

This key can access blog, news, and events collections, but not products, gallery, etc.

#### Path Matching Behavior

**Important**: Total CMS uses prefix-based matching with `str_starts_with()`, so you do NOT need to add `/*` wildcards.

**Example:**
- Allowed path: `/collections/blog`
- Matches: `/collections/blog`, `/collections/blog/123`, `/collections/blog/456/edit`
- Does NOT match: `/collections/products`, `/api/settings`

**Common Patterns:**

| Allowed Path | Matches | Use Case |
|-------------|---------|----------|
| `*` | All routes | Full API access |
| `/collections` | All collections | Access to all collection data |
| `/collections/blog` | Blog collection only | Blog-specific integration |
| `/api/settings` | Settings API | Configuration management |
| `/schemas` | Schema management | Schema editor integration |

### Combining Scopes

Scopes work together - both HTTP methods AND paths must match for a request to be authorized.

**Example: Read-Only Blog Access**
- HTTP Methods: `GET`
- Allowed Paths: `/collections/blog`
- Result: Can view blog objects, cannot create/edit/delete

**Example: Full Blog Editor**
- HTTP Methods: `GET`, `POST`, `PUT`, `DELETE`
- Allowed Paths: `/collections/blog`
- Result: Complete blog management, no access to other collections

**Example: Multi-Collection Content Editor**
- HTTP Methods: `GET`, `POST`, `PUT`
- Allowed Paths: `/collections/blog`, `/collections/news`, `/collections/events`
- Result: Can view and edit three collections, cannot delete

## Using API Keys

### Header Authentication (Recommended)

Include the API key in the `X-API-Key` header:

```bash
curl -H "X-API-Key: tcms_1234567890abcdef1234567890abcdef" \
     https://yoursite.com/collections/blog
```

**JavaScript Example:**
```javascript
fetch('https://yoursite.com/collections/blog', {
  headers: {
    'X-API-Key': 'tcms_1234567890abcdef1234567890abcdef'
  }
})
```

**PHP Example:**
```php
$ch = curl_init('https://yoursite.com/collections/blog');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-Key: tcms_1234567890abcdef1234567890abcdef'
]);
$response = curl_exec($ch);
```

### Query Parameter Authentication

Alternatively, pass the API key as a query parameter:

```
https://yoursite.com/collections/blog?api_key=tcms_1234567890abcdef1234567890abcdef
```

**Note**: Header authentication is preferred for better security (query parameters may be logged).

### Authentication Precedence

If both session authentication and API key are present, Total CMS uses:
1. Session authentication (if valid session exists)
2. API key authentication (fallback)

## Security Best Practices

### Key Management
- **Store keys securely**: Use environment variables or secure vaults, never commit to version control
- **Use specific scopes**: Grant minimum necessary permissions (principle of least privilege)
- **Rotate keys regularly**: Create new keys and revoke old ones periodically
- **Monitor usage**: Check "Last Used" timestamps to identify unused or compromised keys

### Scope Configuration
- **Avoid universal access (`*`)**: Use specific paths unless truly needed
- **Separate keys by purpose**: Create different keys for different integrations
- **Read-only when possible**: Use `GET` only for display-only integrations
- **No DELETE for external services**: Reserve deletion permissions for trusted systems

### Network Security
- **Use HTTPS**: Always use encrypted connections in production
- **Restrict by IP**: Consider firewall rules for server-to-server integrations
- **Header authentication**: Prefer `X-API-Key` header over query parameters

### Incident Response
- **Immediate revocation**: Delete compromised keys immediately
- **Audit access**: Review API access logs for unauthorized activity
- **Key regeneration**: Create new keys after security incidents

## Last Used Tracking

Total CMS automatically tracks the last time each API key was used. This helps:

- **Identify unused keys**: Revoke keys that haven't been used recently
- **Detect suspicious activity**: Unexpected usage patterns may indicate compromise
- **Audit compliance**: Demonstrate API access patterns for security reviews
- **Debugging**: Confirm integrations are actively using their assigned keys

Last used timestamps appear in the API Keys management interface and update with each successful API request.

## Related Documentation

- [REST API Documentation](docs/api/rest-api) - Complete API endpoint reference
- [Field Settings](docs/property-settings/styled-text) - Field configuration options
- [Collections](docs/api/collections) - Collection types and management

## Troubleshooting

### "Invalid API key" Error

**Causes:**
- Key was deleted or revoked
- Key is not properly formatted (should start with `tcms_`)
- Key was not copied completely during creation

**Solution:** Create a new API key and update your integration.

### "Insufficient permissions" Error

**Causes:**
- HTTP method not allowed in key scopes (e.g., trying POST with GET-only key)
- Path not allowed in key scopes (e.g., accessing `/collections/products` when only `/collections/blog` is permitted)

**Solution:** Edit the API key scopes or create a new key with appropriate permissions.

### Path Matching Not Working

**Common Mistake:** Adding `/*` to paths (not needed)

**Incorrect:**
```
Allowed Paths: /collections/blog/*
```

**Correct:**
```
Allowed Paths: /collections/blog
```

Path matching uses prefix matching, so `/collections/blog` automatically matches `/collections/blog/123` and all sub-paths.
