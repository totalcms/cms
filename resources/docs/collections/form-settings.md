---
title: "Collection Form Settings"
description: "Configure Total CMS collection form behavior including help text display, form actions like redirects, webhooks, mailer, Pushover notifications, and validation."
---

# Collection Form Settings

Collection Form Settings allow you to customize the behavior and appearance of object creation and editing forms in your collections. These settings are stored in the `.meta.json` file of each collection and provide control over help text display, form validation, and post-save actions.

## Overview

The `formSettings` object is merged with default form options when building forms for collection objects. All settings are optional and can be combined to create the exact user experience you want.

## Help Display Options

Control how help text appears for form fields in your collection.

### Help Style

The `helpStyle` setting controls the visual presentation of help text throughout the form.

**Available values:**
- `"label"` - Help text overlays the field label
- `"tooltip"` - Help text appears as a dark tooltip above the field
- `"box"` - Help text appears in a box below the field (default)
- `""` - Empty string uses the standard display

```json
{
	"formSettings": {
		"helpStyle": "tooltip"
	}
}
```

### Help on Hover

Show help text when users hover over form fields.

```json
{
	"formSettings": {
		"helpOnHover": true
	}
}
```

**Values:**
- `true` - Help appears when hovering over fields
- `false` - Help display controlled by other settings (default)

### Help on Focus

Show help text when users focus on form fields (clicking or tabbing into them).

```json
{
	"formSettings": {
		"helpOnFocus": true
	}
}
```

**Values:**
- `true` - Help appears when field receives focus
- `false` - Help display controlled by other settings (default)

### Combined Help Settings Example

```json
{
	"formSettings": {
		"helpStyle": "label",
		"helpOnHover": false,
		"helpOnFocus": true
	}
}
```

This configuration displays help as overlaid labels that appear when users focus on fields, but not on hover.

## Form Actions

Form actions execute automatically after specific operations (create, edit, delete). Actions run sequentially in the order defined, allowing you to chain multiple operations together.

### New Actions

Actions that execute after creating a new object. Default behavior redirects to the new object's edit page.

```json
{
	"formSettings": {
		"newActions": [
			{
				"action": "webhook",
				"link": "https://api.example.com/notify",
				"continue": true
			},
			{
				"action": "redirect-object",
				"link": "/admin/collections/products/{id}"
			}
		]
	}
}
```

### Edit Actions

Actions that execute after editing an existing object. Default behavior: no automatic action.

```json
{
	"formSettings": {
		"editActions": [
			{
				"action": "refresh"
			}
		]
	}
}
```

### Delete Actions

Actions that execute after deleting an object. Default behavior redirects to the collection list.

```json
{
	"formSettings": {
		"deleteActions": [
			{
				"action": "redirect",
				"link": "/admin/collections/products"
			}
		]
	}
}
```

## Action Types

The following action types are supported by Total CMS (as defined in `/javascript/totalform/totalform.js`):

- **redirect** - Navigate to a specific URL
- **redirect-object** - Navigate to a URL with object ID (supports `{id}` macro)
- **refresh** - Reload the current page
- **back** - Go back to the previous page (referrer)
- **webhook** / **ajax** - Send POST request to external URL
- **mailer** - Send email via configured mailer

## Common Action Properties

These properties can be used with multiple action types:

### showSuccess

Controls whether the success banner is displayed before navigation actions execute. Available on `redirect`, `redirect-object`, `refresh`, and `back` actions.

**Values:**
- `true` - Show success banner for 2 seconds before navigating (default)
- `false` - Navigate immediately without showing success banner

```json
{
	"action": "redirect",
	"link": "/admin/dashboard",
	"showSuccess": false
}
```

**Note:** The success banner only appears after all actions complete successfully. Navigation actions (redirect, refresh, back) wait for the banner to display before navigating, unless `showSuccess: false` is set.

### continue

Controls whether subsequent actions execute if this action fails. Available on all action types.

**Values:**
- `true` - Continue to next action even if this action fails
- `false` - Stop execution if this action fails (default)

```json
{
	"action": "webhook",
	"link": "https://api.example.com/notify",
	"continue": true
}
```

### Redirect

Redirect to a specific URL after the operation completes.

```json
{
	"action": "redirect",
	"link": "/admin/dashboard"
}
```

**Properties:**
- `action` - Must be `"redirect"`
- `link` - The URL to redirect to
- `showSuccess` - (optional) Show success banner before redirecting (default: `true`)

**Example use cases:**
- Redirect to collection list after delete
- Send users to a custom admin page after save
- Navigate to a dashboard or summary view

**Example with immediate redirect (no success banner):**
```json
{
	"action": "redirect",
	"link": "/admin/dashboard",
	"showSuccess": false
}
```

### Redirect Object

Redirect to a URL that includes the object's ID. The `{id}` placeholder is automatically replaced with the actual object ID. If `{id}` is not present in the link, the ID is appended to the end.

```json
{
	"action": "redirect-object",
	"link": "/admin/collections/products/{id}"
}
```

**Properties:**
- `action` - Must be `"redirect-object"`
- `link` - URL template with optional `{id}` placeholder
- `showSuccess` - (optional) Show success banner before redirecting (default: `true`)

**Example use cases:**
- Stay on the object edit page after creating new object
- Navigate to a custom view page for the object
- Redirect to a related object management page

**With {id} placeholder:**
```json
{
	"action": "redirect-object",
	"link": "/admin/custom/preview/{id}"
}
```

**Without {id} (appends ID to end):**
```json
{
	"action": "redirect-object",
	"link": "/admin/collections/products/"
}
```
This would redirect to `/admin/collections/products/123` (where 123 is the object ID).

**Example with immediate redirect (no success banner):**
```json
{
	"action": "redirect-object",
	"link": "/admin/collections/products/{id}",
	"showSuccess": false
}
```

### Refresh

Refresh the current page to show updated content.

```json
{
	"action": "refresh"
}
```

**Properties:**
- `action` - Must be `"refresh"`
- `showSuccess` - (optional) Show success banner before refreshing (default: `true`)

**Example use cases:**
- Show updated data after edit
- Reload form after successful save
- Update calculated fields or dynamic content

**Example with immediate refresh (no success banner):**
```json
{
	"action": "refresh",
	"showSuccess": false
}
```

### Back

Navigate back to the previous page (referrer). Only works if the referrer is on the same domain.

```json
{
	"action": "back"
}
```

**Properties:**
- `action` - Must be `"back"`
- `showSuccess` - (optional) Show success banner before navigating back (default: `true`)

**Example use cases:**
- Return to the page user came from after save
- Go back after creating a new object
- Navigate back after deletion

**Example with immediate navigation (no success banner):**
```json
{
	"action": "back",
	"showSuccess": false
}
```

**Note:** This action only works if there is a valid referrer from the same hostname. Otherwise, it does nothing.

### Webhook

Call an external URL to notify external systems or trigger integrations. Sends a POST request with the form data as JSON.

```json
{
	"action": "webhook",
	"link": "https://api.example.com/notify"
}
```

**Properties:**
- `action` - Must be `"webhook"` (or `"ajax"`, both work the same)
- `link` - The webhook URL to call
- `continue` - (optional) If `true`, continue to next action even if webhook fails

**Example with continue flag:**
```json
{
	"action": "webhook",
	"link": "https://analytics.example.com/track",
	"continue": true
}
```

**Example use cases:**
- Notify inventory system when products are created/updated
- Send analytics events to external tracking service
- Trigger automated workflows in other systems
- Update external databases or CRMs

**Request details:**
- Method: POST
- Mode: CORS
- Body: JSON-encoded form data

### Mailer

Send an email notification using a configured mailer.

```json
{
	"action": "mailer",
	"mailerId": "notification"
}
```

**Properties:**
- `action` - Must be `"mailer"`
- `mailerId` - The ID of the mailer configuration to use
- `continue` - (optional) If `true`, continue to next action even if email fails

**Twig Variables:** Mailer templates have access to `{{ data.fieldName }}` for form field values and `{{ user.fieldName }}` for the authenticated user who triggered the action (e.g., `{{ user.name }}`, `{{ user.email }}`).

**Example use cases:**
- Send confirmation email after form submission
- Notify admin when new object is created
- Send notification to user after update
- Trigger email workflows

### Pushover

Send a push notification via [Pushover](https://pushover.net). Requires **Pro edition** and Pushover configuration in **Settings > Push Notifications**.

```json
{
	"action": "pushover",
	"title": "New Submission",
	"message": "{{ user.name }} created {{ data.title }}"
}
```

**Properties:**
- `action` - Must be `"pushover"`
- `title` - Notification title (supports Twig)
- `message` - Notification body (supports Twig, required)
- `priority` - Priority level: `-2` (lowest), `-1` (low), `0` (normal), `1` (high), `2` (emergency)
- `sound` - Notification sound (e.g., `cashregister`, `magic`, `none`)
- `link` - Supplementary clickable URL (supports Twig)
- `linkTitle` - Label for the URL (supports Twig)
- `image` - Image attachment object with `collection`, `id`, `property`, and optional `name` (for galleries)
- `continue` - (optional) If `true`, continue to next action even if notification fails

**Twig Variables:** All text fields support `{{ data.fieldName }}` for form values and `{{ user.fieldName }}` for the authenticated user.

**Example with all options:**
```json
{
	"action": "pushover",
	"title": "New Order #{{ data.id }}",
	"message": "{{ data.customerName }} placed an order for ${{ data.total }}",
	"priority": 1,
	"sound": "cashregister",
	"link": "https://mysite.com/admin/collections/orders/{{ data.id }}",
	"linkTitle": "View Order",
	"continue": true
}
```

**Example use cases:**
- Get notified on your phone when new content is submitted
- Alert admin of high-priority form submissions
- Monitor e-commerce orders in real time

For full documentation including sound options, priority levels, and setup instructions, see [Pushover Push Notifications](docs/notifications/pushover).

## Complete Examples

### Blog Collection with Custom Actions

```json
{
	"id": "blog",
	"schema": "blog",
	"name": "Blog Posts",
	"formSettings": {
		"helpStyle": "label",
		"helpOnFocus": true,
		"newActions": [
			{
				"action": "redirect-object",
				"link": "/admin/collections/blog/{id}"
			}
		],
		"editActions": [
			{
				"action": "refresh"
			}
		],
		"deleteActions": [
			{
				"action": "redirect",
				"link": "/admin/collections/blog"
			}
		]
	}
}
```

### Products Collection with Tooltip Help

```json
{
	"id": "products",
	"schema": "products",
	"name": "Products",
	"formSettings": {
		"helpStyle": "tooltip",
		"helpOnHover": true,
		"newActions": [
			{
				"action": "redirect-object",
				"link": "/admin/collections/products/{id}"
			}
		],
		"editActions": [
			{
				"action": "refresh"
			}
		]
	}
}
```

### E-commerce Orders with Webhooks

```json
{
	"id": "orders",
	"schema": "orders",
	"name": "Orders",
	"formSettings": {
		"helpStyle": "box",
		"newActions": [
			{
				"action": "webhook",
				"link": "https://api.inventory.com/reserve"
			},
			{
				"action": "webhook",
				"link": "https://api.analytics.com/track",
				"continue": true
			},
			{
				"action": "redirect-object",
				"link": "/admin/collections/orders/{id}"
			}
		],
		"editActions": [
			{
				"action": "webhook",
				"link": "https://api.inventory.com/update",
				"continue": true
			},
			{
				"action": "refresh"
			}
		]
	}
}
```

### Events Collection with Analytics Tracking

```json
{
	"id": "events",
	"schema": "events",
	"name": "Events",
	"formSettings": {
		"helpOnFocus": true,
		"newActions": [
			{
				"action": "webhook",
				"link": "https://analytics.example.com/event/created",
				"continue": true
			},
			{
				"action": "redirect-object",
				"link": "/admin/collections/events/{id}"
			}
		],
		"deleteActions": [
			{
				"action": "webhook",
				"link": "https://analytics.example.com/event/deleted",
				"continue": true
			},
			{
				"action": "redirect",
				"link": "/admin/collections/events"
			}
		]
	}
}
```

### Simple Collection with Box Help

```json
{
	"id": "team",
	"schema": "team",
	"name": "Team Members",
	"formSettings": {
		"helpStyle": "box",
		"helpOnHover": false
	}
}
```

## How It Works

1. **Form Building** - When a collection form is rendered, the template reads `collection.formSettings`
2. **Merging** - Settings are merged with default form options (custom settings take priority)
3. **CSS Classes** - Settings automatically apply CSS classes to the form container:
   - `.help-tooltip` (when `helpStyle: "tooltip"`)
   - `.help-label` (when `helpStyle: "label"`)
   - `.help-box` (when `helpStyle: "box"`)
   - `.help-on-hover` (when `helpOnHover: true`)
   - `.help-on-focus` (when `helpOnFocus: true`)
4. **JavaScript Handling** - Form JavaScript reads action arrays from `data-*` attributes and executes them sequentially after save/delete operations

## Action Execution Flow

Actions execute in the following order:

1. **Sequential Processing** - Actions run one after another in array order
2. **Error Handling** - If an action fails, subsequent actions won't execute unless `continue: true` is set
3. **Placeholder Replacement** - The `{id}` placeholder in redirect-object actions is replaced with the actual object ID
4. **Message Display** - Messages appear as notifications before other actions execute
5. **Navigation** - Redirect and refresh actions execute last, changing page state

## Important Notes

⚠️ **Empty formSettings** - Empty strings for `formSettings` are automatically converted to empty objects during save

⚠️ **Action Failure** - If an action fails without `continue: true`, subsequent actions won't execute

⚠️ **Webhook Timeouts** - Webhooks have a timeout limit; use `continue: true` for non-critical webhooks

✅ **Default Behavior** - If no actions are specified, Total CMS uses sensible defaults (new→redirect to object, delete→redirect to list)

✅ **Help Styles** - Help style CSS is defined in `/css/forms/help.scss`

## Where to Add formSettings

Form settings are stored in your collection's `.meta.json` file:

**File Location:**
```
/tcms-data/{collection-id}/.meta.json
```

**Example .meta.json:**
```json
{
	"id": "products",
	"schema": "products",
	"name": "Products",
	"formSettings": {
		"helpStyle": "tooltip",
		"newActions": [
			{
				"action": "redirect-object",
				"link": "/admin/collections/products/{id}"
			}
		]
	}
}
```

You can edit this file directly, or use the Collection settings interface in the Total CMS admin panel.
