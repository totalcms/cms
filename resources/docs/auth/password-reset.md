---
title: "Password Reset"
description: "Implement secure password reset workflows in Total CMS with token-based email verification, customizable templates, and configurable expiration."
---

# Password Reset

Total CMS includes a complete password reset workflow that allows users to securely reset their passwords via email.

## Features

- **Secure Token-Based Reset**: Uses cryptographically secure tokens with configurable expiration
- **Email Integration**: Sends password reset links via email with customizable templates
- **User Enumeration Prevention**: Never reveals whether an email exists in the system
- **Single-Use Tokens**: Tokens are automatically invalidated after successful password reset
- **Multi-Collection Support**: Works with custom authentication collections
- **Configurable Expiry**: Set how long reset tokens remain valid

## Configuration

Password reset settings are configured in the `auth` section of your Total CMS configuration:

```php
$settings['auth'] = [
    'enable' => true,
    'collection' => 'auth',

    // Password Reset Settings
    'forgotPasswordMailerId' => '',  // Optional: Custom mailer ID for password reset emails
    'resetTokenExpiry' => 30,        // Token expiration time in minutes (default: 30)

    // Other auth settings...
    'maxAttempts' => 10,
    'deniedTimeout' => 7,
    'persistentLoginDays' => 30,
];
```

### Configuration Options

#### `forgotPasswordMailerId`
Optional mailer ID for custom password reset email templates. If empty, the system uses the built-in default template.

**Example**:
```php
'forgotPasswordMailerId' => 'custom-password-reset',
```

#### `resetTokenExpiry`
Number of minutes before a password reset token expires. Default is 30 minutes.

**Example**:
```php
'resetTokenExpiry' => 60,  // Tokens expire after 1 hour
```

## Default Email Template

Total CMS includes a beautiful, responsive HTML email template for password reset emails. The default template includes:

- Professional styling with your site branding
- Clear call-to-action button
- Token expiration notice
- Alternative link for copy/paste
- Security reminder

The default template is located at:
```
resources/templates/email/password-reset.twig
```

## Custom Email Templates

You can create custom password reset email templates using the Mailer collection. This allows you to fully customize the email design and content.

### Creating a Custom Mailer

1. Navigate to the **Mailer** collection in the Total CMS admin
2. Create a new mailer entry with a unique ID (e.g., `custom-password-reset`)
3. Design your email template using the available Twig variables
4. Set the `forgotPasswordMailerId` configuration to your mailer ID

### Available Twig Variables

When creating custom password reset email templates, the following variables are available via the `data` object:

| Variable | Type | Description |
|----------|------|-------------|
| `data.name` | string | User's name from their account (may be empty) |
| `data.email` | string | Email address receiving the reset link |
| `data.user` | array | Complete user object with all fields from the auth collection |
| `data.resetUrl` | string | Complete password reset URL with token |
| `data.expiryMinutes` | integer | Number of minutes before token expires |
| `data.collection` | string | Authentication collection name |

**Note**: All variables must be accessed via the `data` object (e.g., `{{ data.resetUrl }}`), which is consistent with all custom mailer templates in Total CMS.

### Example Custom Template

```twig
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Password Reset Request</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2>Password Reset Request</h2>

        {% if data.name %}
            <p>Hello {{ data.name }},</p>
        {% else %}
            <p>Hello,</p>
        {% endif %}

        <p>We received a request to reset your password for your account at {{ data.email }}.</p>

        <p style="margin: 30px 0;">
            <a href="{{ data.resetUrl }}"
               style="background-color: #007bff; color: white; padding: 12px 24px;
                      text-decoration: none; border-radius: 4px; display: inline-block;">
                Reset Your Password
            </a>
        </p>

        <p>This link will expire in {{ data.expiryMinutes }} minutes for security reasons.</p>

        <p>If you didn't request this password reset, you can safely ignore this email.
           Your password will remain unchanged.</p>

        <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">

        <p style="font-size: 12px; color: #666;">
            If the button doesn't work, copy and paste this link into your browser:<br>
            <a href="{{ data.resetUrl }}">{{ data.resetUrl }}</a>
        </p>
    </div>
</body>
</html>
```

**Note**: The `data.user` variable provides access to all fields from the user's account. For example:
- `{{ data.user.name }}` - User's name
- `{{ data.user.email }}` - User's email address
- `{{ data.user.customField }}` - Any custom fields you've added to your auth collection

## User Workflow

### Requesting a Password Reset

1. User clicks "Forgot Password?" on the login page
2. Enters their email address
3. System generates a secure token and sends reset email
4. User receives success message (regardless of whether email exists)

### Completing Password Reset

1. User clicks the reset link in their email
2. Token is validated for expiration and authenticity
3. User enters new password (minimum 4 characters)
4. Password is updated and user is redirected to login
5. Token is invalidated (single-use)

## Security Features

### Token Security
- **Cryptographically Secure**: Tokens are generated using `random_bytes()` (64-character hex)
- **Expiration**: Configurable expiration time (default 30 minutes)
- **Single-Use**: Tokens are deleted after successful password reset
- **Invalidation**: New reset requests invalidate previous tokens for the same user

### User Enumeration Prevention
The system never reveals whether an email address exists in the database. Whether the email exists or not, users always see:

> "If an account exists with that email, you will receive a password reset link."

This prevents attackers from discovering valid email addresses.

### Cache Storage
Password reset tokens are stored in the cache system (APCu, Redis, Memcached, or filesystem) with automatic TTL-based expiration. Tokens are not stored in user files for security.

## URLs and Routes

The password reset system uses the following routes:

| Route | Method | Description |
|-------|--------|-------------|
| `/forgot-password[/{collection}]` | GET | Display forgot password form |
| `/forgot-password[/{collection}]` | POST | Process forgot password request |
| `/reset-password/{token}` | GET | Display reset password form |
| `/reset-password/{token}` | POST | Process password reset |

### Collection-Specific URLs

For custom authentication collections:
```
/forgot-password/clients
/reset-password/{token}  (automatically uses correct collection from token)
```

### URL Parameters

The forgot password form supports an `email` query parameter to pre-fill the email field:

```
/forgot-password?email=user@example.com
/forgot-password/clients?email=client@company.com
```

This is useful for:
- Directing users from other parts of your application
- Pre-filling the email when you already know their address
- Creating custom "reset password" links in user profiles

**Example usage:**
```html
<a href="/forgot-password?email={{ user.email }}">Reset your password</a>
```

## Template Customization

### Whitelabel Templates

You can customize the forgot password and reset password forms using whitelabel templates:

**Forgot Password**:
- `whitelabel/forgot-password-above.twig` - Content above the form
- `whitelabel/forgot-password-below.twig` - Content below the form

**Reset Password**:
- `whitelabel/reset-password-above.twig` - Content above the form
- `whitelabel/reset-password-below.twig` - Content below the form

See the [Whitelabel documentation](docs/admin/whitelabel) for more information.

## Troubleshooting

### Email Not Received

1. **Check SMTP Configuration**: Verify your SMTP settings in `config/settings.php`
2. **Check Spam Folder**: Password reset emails may be flagged as spam
3. **Verify Email Service**: Check `storage/logs/email.log` for sending errors
4. **Test Email Service**: Use the Mailer collection to send a test email

### Token Expired Error

If users consistently report expired tokens:

1. Increase the `resetTokenExpiry` value in auth configuration
2. Check cache backend is working properly (APCu, Redis, etc.)
3. Verify server time is correct (`date_default_timezone_set`)

### Custom Mailer Not Working

1. Verify the mailer ID exists in the Mailer collection
2. Check `forgotPasswordMailerId` matches exactly (case-sensitive)
3. Ensure mailer template includes all required variables
4. Check `storage/logs/email.log` for template rendering errors

## API Reference

### PasswordResetService Methods

The `PasswordResetService` provides the following public methods:

#### `createResetToken(string $email, string $collection): array`
Creates a password reset token for the specified email and collection.

**Returns**:
```php
[
    'success' => bool,
    'token' => string,  // Only present if success is true
    'message' => string,
]
```

#### `validateToken(string $token): array`
Validates a password reset token.

**Returns**:
```php
[
    'valid' => bool,
    'email' => string,      // Only present if valid is true
    'collection' => string, // Only present if valid is true
    'message' => string,
]
```

#### `resetPassword(string $token, string $newPassword): array`
Resets a user's password using a valid token.

**Returns**:
```php
[
    'success' => bool,
    'message' => string,
]
```

## Related Documentation

- [Authentication Configuration](docs/operations/configuration#authentication)
- [Whitelabel Templates](docs/admin/whitelabel)
- [Email/SMTP Configuration](docs/operations/configuration#email-smtp)
- [Mailer Collection](docs/notifications/mailer)
