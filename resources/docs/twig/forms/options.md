---
title: "Form Options"
description: "General form options, behavior settings, actions, CSS classes, state management, and status banner configuration."
---

# Form Options

All Total CMS form methods accept `formSettings` to control form behavior, appearance, and functionality.

## Core Options

```twig
{{ cms.form.builder('products', {
    method     : 'POST',           # HTTP method (string, default: 'POST')
    class      : 'custom-form',    # CSS classes for form (string, default: '')
    buildError : 'Save failed',    # Error message to display (string, default: '')
    helpStyle  : 'popup',          # Help text style : 'popup', etc. (string, default: '')
    save       : 'Save Product',   # Save button label (string, default: '')
    delete     : 'Delete Product', # Delete button label (string, default: '')
    formType   : 'object',         # Form type : 'collection', 'schema', 'object' (string, default: '')
    schema     : 'product',        # Schema name to use (string, default: '')
    route      : '/custom/submit', # Custom form submission route (string)
    label      : 'Submit',         # Button label for simple forms (string)
    refresh    : true,             # Refresh page after submission (bool, default: false)
    data       : {},               # Pre-populated form data (array, default: [])
}) }}
```

## Behavior Options

```twig
{{ cms.form.builder('products', {
    autosave: true,                # Enable automatic saving (bool, default: false)
    helpOnHover: true,             # Show help on hover (bool, default: false)
    helpOnFocus: false,            # Show help on focus (bool, default: false)
    hideID: false,                 # Hide the ID field (bool, default: false)
    addOnly: true,                 # Security: Only allow creating new objects, never editing (bool, default: false)
}) }}
```

## Security: Add Only Forms

Use `addOnly: true` for forms on the public side of your website to prevent users from editing existing objects by manipulating URL parameters:

```twig
{# Public registration form - secure against ID manipulation #}
{{ cms.form.builder('users', {
    addOnly: true,
    newActions: [
        {
            action: 'redirect',
            link: '/login'
        }
    ]
}).addField('name').addField('email').addField('password', {field: 'password'}).build() }}
```

**Security Note:** When `addOnly` is enabled:
- Any ID parameter in the URL or form is ignored
- The form will always create a new object, never update existing ones
- Protects against malicious users passing `?id=123` to edit other users' data

## Actions

Configure form actions for different operations. Actions are arrays that support multiple sequential operations:

```twig
{{ cms.form.builder('products', {
    newActions: [                   # Actions for new items (array, default: [])
        {
            action: 'redirect-object',
            link: '?id='
        }
    ],
    editActions: [                  # Actions for editing (array, default: [])
        {
            action: 'refresh'
        }
    ],
    deleteActions: [                # Actions for deletion (array, default: [])
        {
            action: 'redirect',
            link: '/admin/products'
        }
    ]
}) }}
```

### Multiple Sequential Actions

You can chain multiple actions that will execute sequentially. By default, if one action fails, subsequent actions won't execute:

```twig
{{ cms.form.builder('products', {
    newActions: [
        {
            action: 'message',
            text: 'Product created successfully!'
        },
        {
            action: 'redirect-object',
            link: '?id='
        }
    ]
}) }}
```

### Continue on Failure

Add `continue: true` to any action to continue executing subsequent actions even if that action fails. This is useful for optional actions like webhooks, analytics tracking, or notifications that shouldn't block critical user-facing actions.

**Behavior:**
- Failed actions with `continue: true` are logged to the browser console with warnings
- Form state remains "success" - users won't see error messages
- Subsequent actions continue executing normally
- Perfect for fire-and-forget operations

**Basic Example:**
```twig
{{ cms.form.builder('products', {
    newActions: [
        {
            action: 'webhook',
            link: 'https://api.example.com/notify',
            continue: true  # If webhook fails, still redirect
        },
        {
            action: 'redirect-object',
            link: '?id='
        }
    ]
}) }}
```

**Real-World Example - Analytics & Notifications:**
```twig
{{ cms.form.builder('orders', {
    newActions: [
        {
            action: 'webhook',
            link: 'https://analytics.example.com/track',
            continue: true  # Don't block on analytics failure
        },
        {
            action: 'webhook',
            link: 'https://slack.example.com/notify',
            continue: true  # Don't block on Slack notification failure
        },
        {
            action: 'webhook',
            link: 'https://email.example.com/send',
            continue: true  # Don't block on email failure
        },
        {
            action: 'redirect-object',
            link: '/orders/{id}'  # Always redirect user to order page
        }
    ]
}) }}
```

**Mixed Critical & Optional Actions:**
```twig
{{ cms.form.builder('products', {
    newActions: [
        {
            action: 'webhook',
            link: 'https://api.inventory.com/reserve'
            # No continue - inventory reservation is critical
        },
        {
            action: 'webhook',
            link: 'https://api.analytics.com/track',
            continue: true  # Analytics is optional
        },
        {
            action: 'redirect-object',
            link: '/products/{id}'
        }
    ]
}) }}
```

**Console Output:**
When an action with `continue: true` fails, you'll see:
```
Action execution failed: Error: Action webhook failed: 503 Service Unavailable
Action failed but continuing due to continue: true {action: 'webhook', link: '...', continue: true}
```

### Available Action Types

- **redirect**: Redirect to a specific URL
  ```twig
  {action: 'redirect', link: '/admin/products'}
  ```

- **redirect-object**: Redirect to URL with object ID substitution
  ```twig
  {action: 'redirect-object', link: '/admin/products/{id}'}
  ```

- **refresh**: Refresh the current page
  ```twig
  {action: 'refresh'}
  ```

- **message**: Display a message (future: combined with other actions)
  ```twig
  {action: 'message', text: 'Operation successful!'}
  ```

## Auto-Applied CSS Classes

The form system automatically applies CSS classes based on options:

- `.autosave` - when `autosave: true`
- `.help-on-hover` - when `helpOnHover: true`
- `.help-on-focus` - when `helpOnFocus: true`
- `.help-{helpStyle}` - when `helpStyle` is set (e.g., `.help-popup`)
- `.edit-mode` - when method is not 'POST'
- `.formgrid` - when schema has formgrid configuration

## Form State Classes

Forms automatically receive state classes during the save lifecycle that you can use for custom styling:

| Class | Description |
|-------|-------------|
| `.unsaved` | Form has unsaved changes |
| `.processing` | Form is being saved |
| `.success` | Form was saved successfully |
| `.error` | An error occurred during save |
| `.actions-completed` | All post-save actions (webhooks, mailers, redirects) completed successfully |

**Note:** The `.actions-completed` class is added *in addition to* `.success`, not as a replacement. This allows you to show different feedback for "saved" vs "all done":

```css
form.success {
    /* Data saved to server */
    border-color: green;
}

form.success.actions-completed {
    /* All actions completed (emails sent, webhooks fired, etc.) */
    background-color: #e8f5e9;
}
```

## Disabling the Status Banner

By default, Total CMS displays a full-screen status banner overlay when forms are processing, saved, or encounter errors. You can disable this banner for individual forms by adding the `no-status-banner` class:

```twig
{# Disable the global status banner for this form #}
{{ cms.form.builder('products', {
    class: 'no-status-banner'
}).addField('title').addField('price').build() }}
```

When `no-status-banner` is set:
- The global overlay banner won't appear for this form
- The form element still receives all state classes (`.processing`, `.success`, `.error`, `.unsaved`, `.actions-completed`)
- You have full control over styling the form's feedback

**Custom Styling Example:**

```css
/* Custom feedback for forms without the status banner */
form.no-status-banner {
    position: relative;
    transition: border-color 0.3s ease;
}

form.no-status-banner.unsaved {
    border-color: orange;
}

form.no-status-banner.processing {
    border-color: blue;
    opacity: 0.7;
    pointer-events: none;
}

form.no-status-banner.processing::after {
    content: "Saving...";
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(0,0,0,0.8);
    color: white;
    padding: 1rem 2rem;
    border-radius: 4px;
}

form.no-status-banner.success {
    border-color: green;
}

form.no-status-banner.success::after {
    content: "Saved!";
    /* ... styling ... */
}

form.no-status-banner.success.actions-completed::after {
    content: "All done!";
    /* ... styling ... */
}

form.no-status-banner.error {
    border-color: red;
}
```

**Use Cases:**
- Embedded forms where a full-screen overlay is disruptive
- Multiple forms on a page where you want individual feedback
- Custom-designed forms that need specific visual feedback
- Modal/dialog forms where the overlay conflicts with the modal
