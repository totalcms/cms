---
title: "Password Fields"
description: "Set up password fields in Total CMS with automatic confirmation input, match validation, and customizable placeholder text."
---

# Password Fields

Password fields automatically include a confirmation field and built-in validation to ensure both passwords match.

## Automatic Features

**Dual Input Fields:**
- Main password field
- Confirmation password field (automatically appends `-confirm` to the field ID and name)

**Validation:**
- Automatically validates that both password fields match before form submission
- Displays "Passwords do not match" error if values differ
- Both fields must pass validation (e.g., minlength, pattern) individually

## Settings

### `confirmPlaceholder`

Customize the placeholder text for the confirmation field:

```json
{
  "confirmPlaceholder" : "Confirm your new password"
}
```

If not specified, the confirmation field uses the same placeholder as the main password field.

### `numeric`

Set to `true` to show a numeric keyboard on mobile devices. Useful for PIN-style passwords.

```json
{
  "numeric" : true
}
```

**Example with all settings:**
```json
{
  "password": {
    "$ref"        : "https://www.totalcms.co/schemas/properties/password.json",
    "label"       : "Password",
    "placeholder" : "Enter your password",
    "minLength"   : 8,
    "settings"    : {
      "minlength"          : 8,
      "confirmPlaceholder" : "Confirm your password"
    }
  }
}
```

## How Validation Works

The password field JavaScript automatically:
1. Finds the confirmation field using the main field's ID + `-confirm`
2. Compares both field values during form validation
3. Sets custom validity message if passwords don't match
4. Prevents form submission until passwords match

**HTML structure generated:**
```html
<!-- Main password field -->
<input type="password" id="field-abc123" name="password" placeholder="Enter your password">

<!-- Confirmation field (automatically created) -->
<input type="password" id="field-abc123-confirm" name="password-confirm" placeholder="Confirm your password">
```

## Common Use Cases

**User registration:**
```json
{
  "password": {
    "$ref"        : "https://www.totalcms.co/schemas/properties/password.json",
    "label"       : "Password",
    "placeholder" : "Create a password",
    "minLength"   : 8,
    "settings"    : {
      "minlength"          : 8,
      "confirmPlaceholder" : "Confirm your password"
    }
  }
}
```

**Password reset:**
```json
{
  "newPassword": {
    "$ref"        : "https://www.totalcms.co/schemas/properties/password.json",
    "label"       : "New Password",
    "placeholder" : "Enter new password",
    "minLength"   : 8,
    "settings"    : {
      "minlength"          : 8,
      "confirmPlaceholder" : "Re-enter new password"
    }
  }
}
```
