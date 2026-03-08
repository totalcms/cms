---
title: "Pushover Push Notifications"
description: "Configure Pushover push notifications for Total CMS form actions and system events."
---

# Pushover Push Notifications

Total CMS integrates with [Pushover](https://pushover.net) to send push notifications to your phone, tablet, or desktop when form actions are triggered. This is a **Pro edition** feature.

## Setup

### 1. Create a Pushover Account

Sign up at [pushover.net](https://pushover.net) and install the Pushover app on your device. Pushover charges a one-time $5 fee per platform (iOS, Android, Desktop).

### 2. Create an Application

Go to [pushover.net/apps/build](https://pushover.net/apps/build) and create a new application for your Total CMS site. This gives you an **Application Token**.

### 3. Get Your User Key

Your **User Key** is displayed on your [Pushover dashboard](https://pushover.net/dashboard).

### 4. Configure Total CMS

Go to **Settings > Push Notifications** in the Total CMS admin and enter your Application Token and User Key.

### 5. Test Your Configuration

Use the **Test Push Notifications** section at the bottom of the settings page to send a test notification and verify everything is working.

## Form Action

The `pushover` action sends a push notification when a form is saved or an object is deleted. Add it to your collection's `formSettings` in `.meta.json`.

### Basic Example

```json
{
	"action": "pushover",
	"title": "New Submission",
	"message": "A new form was submitted."
}
```

### Properties

| Property | Type | Required | Default | Description |
|----------|------|----------|---------|-------------|
| `action` | string | Yes | | Must be `"pushover"` |
| `title` | string | No | App name | Notification title (max 250 characters). Supports Twig. |
| `message` | string | Yes | | Notification body (max 1024 characters). Supports Twig. |
| `priority` | int | No | `0` | Notification priority level (see below) |
| `sound` | string | No | User default | Notification sound (see below) |
| `link` | string | No | | Supplementary clickable URL (max 512 characters). Supports Twig. |
| `linkTitle` | string | No | | Label for the supplementary URL (max 100 characters). Supports Twig. |
| `continue` | bool | No | `false` | Continue to next action even if notification fails |

### Twig Variables

All text fields (`title`, `message`, `link`, `linkTitle`) support Twig template syntax with access to:

- **`{{ data.fieldName }}`** - Form field values from the submitted object
- **`{{ user.fieldName }}`** - The authenticated user who triggered the action

```json
{
	"action": "pushover",
	"title": "{{ data.schema }} Updated",
	"message": "{{ user.name }} updated {{ data.title }}",
	"link": "https://mysite.com/admin/collections/blog/{{ data.id }}",
	"linkTitle": "View in Admin"
}
```

### Priority Levels

| Value | Name | Behavior |
|-------|------|----------|
| `-2` | Lowest | No notification generated, badge increment only |
| `-1` | Low | No sound or vibration, popup notification only |
| `0` | Normal | Sound, vibration, and alert per device settings |
| `1` | High | Bypasses quiet hours, always sounds and vibrates |
| `2` | Emergency | Repeats every 60 seconds until acknowledged (up to 1 hour) |

### Sounds

| Value | Sound |
|-------|-------|
| *(empty)* | User's default sound |
| `pushover` | Pushover (default) |
| `bike` | Bike |
| `bugle` | Bugle |
| `cashregister` | Cash Register |
| `classical` | Classical |
| `cosmic` | Cosmic |
| `falling` | Falling |
| `gamelan` | Gamelan |
| `incoming` | Incoming |
| `intermission` | Intermission |
| `magic` | Magic |
| `mechanical` | Mechanical |
| `pianobar` | Piano Bar |
| `siren` | Siren |
| `spacealarm` | Space Alarm |
| `tugboat` | Tugboat |
| `alien` | Alien Alarm (long) |
| `climb` | Climb (long) |
| `persistent` | Persistent (long) |
| `echo` | Pushover Echo (long) |
| `updown` | Up Down (long) |
| `vibrate` | Vibrate Only |
| `none` | None (silent) |

Users can also upload custom sounds to their Pushover account and reference them by name.

## Complete Examples

### Blog Notification on New Post

```json
{
	"formSettings": {
		"newActions": [
			{
				"action": "pushover",
				"title": "New Blog Post",
				"message": "{{ user.name }} published: {{ data.title }}",
				"sound": "magic",
				"link": "https://mysite.com/blog/{{ data.slug }}",
				"linkTitle": "Read Post"
			},
			{
				"action": "redirect-object",
				"link": "/admin/collections/blog/{id}"
			}
		]
	}
}
```

### E-commerce Order Notifications

```json
{
	"formSettings": {
		"newActions": [
			{
				"action": "pushover",
				"title": "New Order #{{ data.id }}",
				"message": "{{ data.customerName }} placed an order for ${{ data.total }}",
				"priority": 1,
				"sound": "cashregister",
				"link": "https://mysite.com/admin/collections/orders/{{ data.id }}",
				"linkTitle": "View Order"
			},
			{
				"action": "mailer",
				"mailerId": "order-confirmation"
			},
			{
				"action": "redirect-object",
				"link": "/admin/collections/orders/{id}"
			}
		],
		"deleteActions": [
			{
				"action": "pushover",
				"title": "Order Cancelled",
				"message": "Order #{{ data.id }} was cancelled by {{ user.name }}",
				"priority": 1,
				"continue": true
			},
			{
				"action": "redirect",
				"link": "/admin/collections/orders"
			}
		]
	}
}
```

### Non-Critical Analytics with Continue

```json
{
	"action": "pushover",
	"title": "Content Updated",
	"message": "{{ data.title }} was modified",
	"priority": -1,
	"continue": true
}
```

Using `continue: true` ensures that if the Pushover API is unreachable, the remaining form actions (like redirects) still execute.

## Rate Limits

Pushover allows 10,000 messages per month on the free tier. Each successful API call counts as one message regardless of how many devices the user has. The quota resets on the 1st of each month.

## See Also

- [Form Settings](docs/collections/form-settings) - Configure form actions for collections
- [Mailer Actions](docs/collections/form-settings#mailer) - Send email notifications from form actions
- [Webhook Actions](docs/collections/form-settings#webhook) - Call external URLs from form actions
