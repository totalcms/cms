# Access Group Testing Guide

This guide provides comprehensive testing scenarios for the Total CMS access group enforcement system.

## Overview

The access control system has been implemented with:
- **AccessControlService**: Core permission checking logic
- **CollectionAccessMiddleware**: Automatic enforcement on collection routes
- **5 Test Access Groups**: Various permission levels for testing
- **5 Test Users**: One user per access group

All test users share the same password: `password123`

## Test Users and Their Permissions

### 1. Admin User
- **Email**: admin-user@test.com
- **Password**: password123
- **Group**: admin
- **Permissions**: Full access to everything (bypasses all checks)

### 2. Viewer User
- **Email**: viewer-user@test.com
- **Password**: password123
- **Group**: viewer
- **Permissions**: Read-only access to all collections (GET only)

### 3. Blogger User
- **Email**: blogger-user@test.com
- **Password**: password123
- **Group**: blogger
- **Permissions**: Full CRUD access to blog collection only

### 4. Editor User
- **Email**: editor-user@test.com
- **Password**: password123
- **Group**: editor
- **Permissions**: Full CRUD access to blog and news collections

### 5. Limited Blogger User
- **Email**: limited-user@test.com
- **Password**: password123
- **Group**: limited-blogger
- **Permissions**: Read-only access to blog collection only (GET)

## Testing Prerequisites

1. Ensure authentication is enabled in `tcms.php`:
```json
{
    "auth": {
        "enable": true
    }
}
```

2. Ensure you have test collections:
   - `blog` collection
   - `news` collection (or create one)
   - Any other collection for testing denial

3. Have some test objects in these collections for GET requests

## Testing Workflow

### Step 1: Login to Get Session Cookie

Before testing collection access, you need to login to get a session cookie:

```bash
# Login as a test user
curl -X POST http://totalcms.test/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "viewer-user@test.com", "password": "password123"}' \
  -c cookies.txt
```

The `-c cookies.txt` saves the session cookie for subsequent requests.

### Step 2: Test Collection Access

Use the saved cookie for authenticated requests:

```bash
# Example: GET request to blog collection
curl -X GET http://totalcms.test/api/collections/blog \
  -b cookies.txt
```

The `-b cookies.txt` sends the session cookie with your request.

## Test Scenarios

### Scenario 1: Admin User (Full Access)

**Login:**
```bash
curl -X POST http://totalcms.test/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "admin-user@test.com", "password": "password123"}' \
  -c admin-cookies.txt
```

**Tests:**
```bash
# GET blog - Should succeed (200)
curl -X GET http://totalcms.test/api/collections/blog -b admin-cookies.txt

# POST blog - Should succeed (200/201)
curl -X POST http://totalcms.test/api/collections/blog \
  -H "Content-Type: application/json" \
  -d '{"title": "Test Post"}' \
  -b admin-cookies.txt

# DELETE blog - Should succeed (200/204)
curl -X DELETE http://totalcms.test/api/collections/blog/test-id \
  -b admin-cookies.txt

# GET any collection - Should succeed (200)
curl -X GET http://totalcms.test/api/collections/products -b admin-cookies.txt
```

**Expected**: All requests succeed - admin bypasses all checks.

---

### Scenario 2: Viewer User (Read-Only All Collections)

**Login:**
```bash
curl -X POST http://totalcms.test/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "viewer-user@test.com", "password": "password123"}' \
  -c viewer-cookies.txt
```

**Tests:**
```bash
# GET blog - Should succeed (200)
curl -X GET http://totalcms.test/api/collections/blog -b viewer-cookies.txt

# GET news - Should succeed (200)
curl -X GET http://totalcms.test/api/collections/news -b viewer-cookies.txt

# GET any other collection - Should succeed (200)
curl -X GET http://totalcms.test/api/collections/products -b viewer-cookies.txt

# POST blog - Should fail (403)
curl -X POST http://totalcms.test/api/collections/blog \
  -H "Content-Type: application/json" \
  -d '{"title": "Test"}' \
  -b viewer-cookies.txt

# PUT blog - Should fail (403)
curl -X PUT http://totalcms.test/api/collections/blog/test-id \
  -H "Content-Type: application/json" \
  -d '{"title": "Updated"}' \
  -b viewer-cookies.txt

# DELETE blog - Should fail (403)
curl -X DELETE http://totalcms.test/api/collections/blog/test-id \
  -b viewer-cookies.txt
```

**Expected**:
- All GET requests succeed (200)
- All POST/PUT/DELETE requests fail (403)
- Access to all collections works (all: true)

---

### Scenario 3: Blogger User (Full CRUD Blog Only)

**Login:**
```bash
curl -X POST http://totalcms.test/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "blogger-user@test.com", "password": "password123"}' \
  -c blogger-cookies.txt
```

**Tests:**
```bash
# GET blog - Should succeed (200)
curl -X GET http://totalcms.test/api/collections/blog -b blogger-cookies.txt

# POST blog - Should succeed (200/201)
curl -X POST http://totalcms.test/api/collections/blog \
  -H "Content-Type: application/json" \
  -d '{"title": "Blogger Post"}' \
  -b blogger-cookies.txt

# PUT blog - Should succeed (200)
curl -X PUT http://totalcms.test/api/collections/blog/test-id \
  -H "Content-Type: application/json" \
  -d '{"title": "Updated Post"}' \
  -b blogger-cookies.txt

# DELETE blog - Should succeed (200/204)
curl -X DELETE http://totalcms.test/api/collections/blog/test-id \
  -b blogger-cookies.txt

# GET news - Should fail (403)
curl -X GET http://totalcms.test/api/collections/news -b blogger-cookies.txt

# POST news - Should fail (403)
curl -X POST http://totalcms.test/api/collections/news \
  -H "Content-Type: application/json" \
  -d '{"title": "News"}' \
  -b blogger-cookies.txt
```

**Expected**:
- All blog requests succeed (200/201/204)
- All news requests fail (403)
- Access limited to blog collection only

---

### Scenario 4: Editor User (Full CRUD Blog + News)

**Login:**
```bash
curl -X POST http://totalcms.test/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "editor-user@test.com", "password": "password123"}' \
  -c editor-cookies.txt
```

**Tests:**
```bash
# GET blog - Should succeed (200)
curl -X GET http://totalcms.test/api/collections/blog -b editor-cookies.txt

# POST blog - Should succeed (200/201)
curl -X POST http://totalcms.test/api/collections/blog \
  -H "Content-Type: application/json" \
  -d '{"title": "Editor Blog Post"}' \
  -b editor-cookies.txt

# GET news - Should succeed (200)
curl -X GET http://totalcms.test/api/collections/news -b editor-cookies.txt

# POST news - Should succeed (200/201)
curl -X POST http://totalcms.test/api/collections/news \
  -H "Content-Type: application/json" \
  -d '{"title": "Editor News Post"}' \
  -b editor-cookies.txt

# DELETE blog - Should succeed (200/204)
curl -X DELETE http://totalcms.test/api/collections/blog/test-id \
  -b editor-cookies.txt

# DELETE news - Should succeed (200/204)
curl -X DELETE http://totalcms.test/api/collections/news/test-id \
  -b editor-cookies.txt

# GET products - Should fail (403)
curl -X GET http://totalcms.test/api/collections/products -b editor-cookies.txt
```

**Expected**:
- All blog and news requests succeed (200/201/204)
- Requests to other collections fail (403)
- Access limited to blog and news collections

---

### Scenario 5: Limited Blogger User (Read-Only Blog)

**Login:**
```bash
curl -X POST http://totalcms.test/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "limited-user@test.com", "password": "password123"}' \
  -c limited-cookies.txt
```

**Tests:**
```bash
# GET blog - Should succeed (200)
curl -X GET http://totalcms.test/api/collections/blog -b limited-cookies.txt

# POST blog - Should fail (403)
curl -X POST http://totalcms.test/api/collections/blog \
  -H "Content-Type: application/json" \
  -d '{"title": "Test"}' \
  -b limited-cookies.txt

# PUT blog - Should fail (403)
curl -X PUT http://totalcms.test/api/collections/blog/test-id \
  -H "Content-Type: application/json" \
  -d '{"title": "Updated"}' \
  -b limited-cookies.txt

# DELETE blog - Should fail (403)
curl -X DELETE http://totalcms.test/api/collections/blog/test-id \
  -b limited-cookies.txt

# GET news - Should fail (403)
curl -X GET http://totalcms.test/api/collections/news -b limited-cookies.txt
```

**Expected**:
- Only GET blog succeeds (200)
- All other requests fail (403)
- Most restrictive permissions

---

## Special Cases

### API Key Bypass

API keys bypass all access group checks (trust model).

**Test:**
```bash
# Create an API key first (as admin or through existing API key)
# Then use it without any access restrictions

curl -X GET http://totalcms.test/api/collections/blog \
  -H "X-API-Key: your-api-key-here"

curl -X POST http://totalcms.test/api/collections/blog \
  -H "X-API-Key: your-api-key-here" \
  -H "Content-Type: application/json" \
  -d '{"title": "API Key Post"}'
```

**Expected**: All requests succeed regardless of collection or method.

---

### Unauthenticated Requests

**Test:**
```bash
# Try to access collection without login
curl -X GET http://totalcms.test/api/collections/blog
```

**Expected**: 403 Forbidden with message "Authentication required"

---

### Empty/Missing Permissions

If a user has no groups or empty permissions, they should be denied access.

**Test:**
1. Create a user with no groups: `"groups": []`
2. Try to access any collection
3. Should receive 403 Forbidden

---

## Verification Checklist

Use this checklist to verify all aspects of the access control system:

- [ ] Admin user can access all collections with all methods
- [ ] Viewer user can GET all collections but cannot POST/PUT/DELETE
- [ ] Blogger user has full CRUD on blog but denied on other collections
- [ ] Editor user has full CRUD on blog and news but denied on others
- [ ] Limited blogger can only GET blog, denied all other methods and collections
- [ ] API keys bypass all access checks
- [ ] Unauthenticated requests are denied
- [ ] Users with no groups are denied access
- [ ] Error messages are appropriate (don't leak system info)
- [ ] Multiple groups work (user gets combined access from all groups)

---

## Testing Multiple Groups

To test the iterative permission checking (where first allowed group wins):

1. Create a test user with multiple groups:
```json
{
    "id": "multi-group-test-com",
    "email": "multi-group@test.com",
    "groups": ["viewer", "blogger"]
}
```

2. Test behavior:
   - Should be able to GET any collection (from viewer)
   - Should be able to POST/PUT/DELETE blog (from blogger)
   - Should NOT be able to POST news (neither group allows)

**Expected**: Permissions are evaluated iteratively - first group that allows the action wins.

---

## Troubleshooting

### 403 Errors When Should Succeed

1. Verify user is properly logged in (check cookies.txt exists)
2. Verify access group has correct permissions in `.system/access-groups.json`
3. Verify user has correct group assignment in `tcms-data/auth/{user}.json`
4. Check logs for specific denial reason

### All Requests Succeed When Should Fail

1. Verify auth is enabled in `tcms.php`
2. Verify middleware is properly added to routes in `config/routes/collections.php`
3. Check if user is in admin group (bypasses all checks)
4. Verify you're not using an API key (bypasses all checks)

### Session Issues

1. Clear cookies: `rm cookies.txt`
2. Login again to get fresh session
3. Verify session is working: `curl http://totalcms.test/api/auth/check -b cookies.txt`

---

## Next Steps

After verifying collection access control works:

1. **Implement schema access control**: Apply similar middleware to schema routes
2. **Implement template access control**: Control access to template management
3. **Implement settings access control**: Control access to system settings
4. **Admin UI updates**: Hide/disable UI elements based on user permissions
5. **Audit logging**: Track who accessed/modified what and when

---

## Summary

This testing guide provides comprehensive scenarios to verify the access group enforcement system works correctly. Test each scenario systematically and use the checklist to ensure complete coverage.

For any issues or unexpected behavior, consult the troubleshooting section or review the implementation in:
- `src/Domain/Auth/Service/AccessControlService.php`
- `src/Middleware/CollectionAccessMiddleware.php`
- `config/routes/collections.php`
