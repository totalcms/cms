---
title: "White Label Dashboard"
description: "Customize the Total CMS admin with white label branding including custom logos, login pages, CSS variables, and localized form labels."
---

# White Label Dashboard

The white label feature allows you to customize the Total CMS admin dashboard with your own branding, making it appear as your own custom CMS solution for clients.

## Edition Access

White label templates are split into two tiers:

| Edition | Feature | Templates |
|---------|---------|-----------|
| **Standard** | `whitelabel_standard` | Login, forgot/reset password, and download auth customization (above-form content + form options), admin welcome message |
| **Pro** | `whitelabel_pro` | Everything in Standard, plus below-form content, admin logo, admin home page, head injection, and body-below injection |
| **Lite** | — | No white label access |

If a user downgrades, white label templates are ignored (not deleted). Upgrading re-enables them automatically.

## Overview

White label templates let you inject custom content into specific areas of the admin dashboard. All white label templates are stored in the `whitelabel/` category inside the Builder:

```
tcms-data/builder/whitelabel/
  admin-logo.twig
  login-above.twig
  login-options.twig
  ...
```

## Available Templates

### Standard Edition (`whitelabel_standard`)

Content templates:

- `whitelabel/login-above` — Content above the login form
- `whitelabel/forgot-password-above` — Content above the forgot password form
- `whitelabel/reset-password-above` — Content above the reset password form
- `whitelabel/download-auth-above` — Content above the download auth form
- `whitelabel/admin-welcome` — Welcome message on the dashboard home page

Form options templates (JSON for customizing form labels):

- `whitelabel/login-options` — Login form labels and options
- `whitelabel/forgot-password-options` — Forgot password form labels
- `whitelabel/reset-password-options` — Reset password form labels
- `whitelabel/download-auth-options` — Download auth form labels

### Pro Edition (`whitelabel_pro`)

Everything in Standard, plus:

- `whitelabel/login-below` — Content below the login form
- `whitelabel/forgot-password-below` — Content below the forgot password form
- `whitelabel/reset-password-below` — Content below the reset password form
- `whitelabel/download-auth-below` — Content below the download auth form
- `whitelabel/admin-logo` — Custom logo in the sidebar
- `whitelabel/admin-home` — Replace the entire admin home page
- `whitelabel/admin-head` — Inject into the `<head>` section (CSS, fonts, meta tags)
- `whitelabel/admin-body-below` — Inject before `</body>` (chat widgets, scripts)

## Setup

### Creating White Label Templates

**Using the Builder (Recommended)**

1. Navigate to `/admin/builder` in the admin dashboard
2. Click "+ New Template"
3. Select **White Label** from the Type dropdown
4. Enter the template name (e.g., `login-above`)
5. Add your custom content and save

**Manually via File System**

Place `.twig` files directly in the builder whitelabel directory:

```
tcms-data/
  builder/
    whitelabel/
      admin-logo.twig
      login-above.twig
      login-options.twig
      ...
```

### Template Guidelines

Only create the templates you want to customize. If a white label template doesn't exist, Total CMS uses its default content.

## Template Examples

### Custom Logo

**File:** `whitelabel/admin-logo.twig` (Pro)

The custom logo must be wrapped in a link with the class `custom-logo`:

```twig
<a class="custom-logo" href="" title="Dashboard Home">
	<img src="/path/to/your-logo.png" alt="Your Company" style="max-height: 32px;">
</a>
```

```twig
<a class="custom-logo" href="" title="Dashboard Home">
	{{ cms.render.image("logo") }}
</a>
```

Or with an SVG:

```twig
<a class="custom-logo" href="" title="Home">
	{{ cms.svg("logo") }}
</a>
```

**Important:** The `<a class="custom-logo">` wrapper is required for proper styling and layout. Set the href to blank if you want it to go to the Dashboard homepage. If you want to link outside the Dashboard, you must provide the full URL, not just the path.

### Admin Welcome Message

**File:** `whitelabel/admin-welcome.twig` (Standard)

Customize the welcome message at the top of the admin dashboard:

```twig
<div class="dashboard-welcome">
	<h1>Welcome to {{ cms.config('siteName') ?: 'Your CMS' }}!</h1>
	<p class="dashboard-subtitle">Manage your website content with ease.</p>
</div>
```

**With Personalized Greeting:**

```twig
{% set user = cms.auth.userData %}
<div class="dashboard-welcome">
	<h1>
		{% if user %}
			Hello {{ user.name }}, welcome back!
		{% else %}
			Welcome to Your Custom CMS!
		{% endif %}
	</h1>
	<p class="dashboard-subtitle">
		{% set hour = "now"|date("H") %}
		{% if hour >= 5 and hour < 12 %}
			Good morning! Ready to create something amazing?
		{% elseif hour >= 12 and hour < 17 %}
			Good afternoon! Let's get some work done.
		{% else %}
			Good evening! Time to wrap up the day.
		{% endif %}
	</p>
</div>
```

**Note:** Use `whitelabel/admin-welcome.twig` when you only want to customize the welcome message. Use `whitelabel/admin-home.twig` (Pro only) when you need to replace the entire dashboard home page.

### Login Page - Above Form

**File:** `whitelabel/login-above.twig` (Standard)

```twig
<div class="login-branding">
	<img src="/images/company-logo.png" alt="Your Company" style="max-width: 200px; margin-bottom: 2rem;">
	<h2>Client Portal Login</h2>
	<p>Welcome to your content management system.</p>
</div>
```

#### Localizing Login Form Labels

**File:** `whitelabel/login-options.twig` (Standard)

```json
{
    "emailLabel": "Correo Electrónico",
    "passwordLabel": "Contraseña",
    "rememberLabel": "Mantenerme conectado",
    "submitLabel": "Iniciar sesión",
    "forgotPasswordLabel": "¿Olvidó su contraseña?"
}
```

**Available Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `emailLabel` | Email | Label for the email input field |
| `passwordLabel` | Password | Label for the password input field |
| `rememberLabel` | Keep me signed in | Label for the persistent login checkbox |
| `submitLabel` | Sign in | Text for the submit button |
| `forgotPasswordLabel` | Forgot Password? | Text for the forgot password link |
| `showForgotPassword` | true | Whether to show the forgot password link |

### Login Page - Below Form

**File:** `whitelabel/login-below.twig` (Pro)

```twig
<div class="login-footer">
	<p>Need help? Contact us at <a href="mailto:support@yourcompany.com">support@yourcompany.com</a></p>
	<p class="copyright">&copy; {{ "now"|date("Y") }} Your Company. All rights reserved.</p>
</div>
```

### Download Authentication - Above Form

**File:** `whitelabel/download-auth-above.twig` (Standard)

```twig
<div class="download-header">
	<h2>Secure File Access</h2>
	<p>Enter the password to access this file.</p>
</div>
```

#### Localizing Download Auth Form Labels

**File:** `whitelabel/download-auth-options.twig` (Standard)

```json
{
    "passwordLabel": "Contraseña",
    "submitLabel": "Descargar"
}
```

**Available Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `passwordLabel` | Password | Label for the password input field |
| `submitLabel` | Download | Text for the submit button |

### Download Authentication - Below Form

**File:** `whitelabel/download-auth-below.twig` (Pro)

```twig
<div class="download-footer">
	<p class="help-text">
		Files are protected and require authentication.
		If you need assistance, please contact support.
	</p>
</div>
```

### Forgot Password - Above Form

**File:** `whitelabel/forgot-password-above.twig` (Standard)

```twig
<div class="forgot-password-header">
	<h2>Forgot Your Password?</h2>
	<p>Enter your email address and we'll send you a reset link.</p>
</div>
```

#### Localizing Forgot Password Form Labels

**File:** `whitelabel/forgot-password-options.twig` (Standard)

```json
{
    "emailLabel": "Correo Electrónico",
    "submitLabel": "Enviar Enlace de Restablecimiento",
    "backToLoginLabel": "&larr; Volver al Inicio de Sesión"
}
```

**Available Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `emailLabel` | Email | Label for the email input field |
| `submitLabel` | Send Reset Link | Text for the submit button |
| `backToLoginLabel` | &larr; Back to Login | Text for the back to login link |

### Reset Password - Above Form

**File:** `whitelabel/reset-password-above.twig` (Standard)

```twig
<div class="reset-password-header">
	<h2>Reset Your Password</h2>
	<p>Enter your new password below.</p>
</div>
```

#### Localizing Reset Password Form Labels

**File:** `whitelabel/reset-password-options.twig` (Standard)

```json
{
    "passwordLabel": "Nueva Contraseña",
    "confirmPasswordLabel": "Confirmar Contraseña",
    "submitLabel": "Restablecer Contraseña"
}
```

**Available Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `passwordLabel` | New Password | Label for the new password input field |
| `confirmPasswordLabel` | Confirm Password | Label for the confirm password input field |
| `submitLabel` | Reset Password | Text for the submit button |

### Admin Head Section

**File:** `whitelabel/admin-head.twig` (Pro)

Inject custom content into the `<head>` section of all admin pages. Perfect for custom CSS, analytics, or meta tags:

```twig
{# Custom CSS for branding #}
<style>
:root {
	--totalform-accent: 45 0.15 220;  /* Custom brand color in OKLCH */
}
.dash-sidebar {
	background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
}
</style>

{# Custom fonts #}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

{# Custom favicon #}
<link rel="icon" type="image/png" href="/images/custom-favicon.png">
```

**Common Use Cases:**
- Custom CSS for dashboard theming
- Web fonts for consistent branding
- Analytics/tracking scripts
- Custom favicons
- Meta tags for internal tools

### Admin Body Bottom

**File:** `whitelabel/admin-body-below.twig` (Pro)

Inject content before the closing `</body>` tag. Ideal for chat widgets, help systems, or custom JavaScript:

```twig
{# Customer support chat widget #}
<script src="https://chat.yourservice.com/widget.js" async></script>

{# Custom admin functionality #}
<script>
document.addEventListener('DOMContentLoaded', function() {
	document.addEventListener('keydown', function(e) {
		if (e.ctrlKey && e.key === 'k') {
			e.preventDefault();
			// Open quick search
		}
	});
});
</script>
```

**Common Use Cases:**
- Live chat widgets (Intercom, Drift, Help Scout)
- Help documentation widgets
- Custom JavaScript functionality
- Keyboard shortcuts
- Performance monitoring scripts

### Admin Home Page

**File:** `whitelabel/admin-home.twig` (Pro)

Replace the entire admin home page with custom content:

```twig
<div class="custom-dashboard">
	<h1>Welcome to Your CMS</h1>

	<div class="dashboard-grid">
		<div class="dashboard-card">
			<h3>Quick Start Guide</h3>
			<ul>
				<li><a href="/admin/collections/pages">Manage Pages</a></li>
				<li><a href="/admin/collections/blog">Manage Blog Posts</a></li>
			</ul>
		</div>

		<div class="dashboard-card">
			<h3>Need Help?</h3>
			<p>Contact our support team:</p>
			<p><strong>Email:</strong> support@yourcompany.com</p>
		</div>
	</div>
</div>

<style>
.custom-dashboard { padding: 2rem; }
.dashboard-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 1.5rem;
	margin-top: 2rem;
}
.dashboard-card {
	background: white;
	border: 1px solid oklch(var(--totalform-border-color));
	border-radius: var(--totalform-radius);
	padding: 1.5rem;
}
.dashboard-card h3 {
	margin-top: 0;
	color: oklch(var(--totalform-accent));
}
</style>
```

### Dashboard Widgets

When customizing the admin home page, you can use the built-in dashboard widgets macro for a consistent look:

```twig
{% import 'dashboard-widgets.twig' as dashboard %}

{% set stats = cms.admin.dashboardStats() %}
{% set collections = cms.admin.dashboardRecentCollections() %}

{{ dashboard.welcomeMessage('Admin') }}
{{ dashboard.statsCard(stats) }}
{{ dashboard.quickActions() }}
{{ dashboard.collectionsTable(collections) }}
```

**Available Widgets:**

| Widget | Description |
|--------|-------------|
| `dashboard.statsCard(stats)` | Quick statistics overview |
| `dashboard.quickActions()` | Quick action buttons |
| `dashboard.collectionsTable(collections)` | Collections table with recent activity |
| `dashboard.recentActivity()` | Recent activity feed |
| `dashboard.emptyCollectionsAlert(emptyCollections)` | Alert for collections without content |
| `dashboard.systemStatus(systemStatus)` | System information (admin only) |
| `dashboard.welcomeMessage(name)` | Welcome message with optional name |
| `dashboard.newUserWelcome()` | Welcome screen for new users |

## Styling

White label content inherits the admin dashboard's CSS variables for consistent theming:

```css
--totalform-accent         /* Primary accent color */
--totalform-border-color   /* Border colors */
--totalform-text-color     /* Text color */
--totalform-bg             /* Background color */
--totalform-radius         /* Border radius */
--totalform-nearwhite      /* Light background */
--totalform-darkgray       /* Dark text */
```

Example usage:

```twig
<div style="border: 1px solid oklch(var(--totalform-border-color)); border-radius: var(--totalform-radius);">
	<!-- Content -->
</div>
```

## Best Practices

1. **Keep It Simple** — Focus on essential branding elements
2. **Test on Multiple Devices** — Ensure content looks good on desktop, tablet, and mobile
3. **Use Relative Paths** — Use `/images/logo.png` or full URLs, not relative paths
4. **Maintain Accessibility** — Include alt text, use semantic HTML, ensure color contrast
5. **Consider Updates** — White label templates persist across T3 updates

## Logo Guidelines

- **Wrapper Required:** Must be wrapped in `<a class="custom-logo">`
- **Recommended Height:** 30-50px for optimal display
- **Format:** PNG, SVG, or JPG (SVG recommended)
- **Background:** Transparent backgrounds work best
- **File Size:** Keep under 100KB for fast loading

## Troubleshooting

### Logo Not Displaying
- Verify the `<a class="custom-logo">` wrapper is present
- Check that the image path is correct and the file exists
- Check browser console for 404 errors

### Template Not Loading
- Confirm template file is in `tcms-data/builder/whitelabel/` directory
- Check file naming matches exactly (case-sensitive)
- Verify file extension is `.twig`
- Clear Twig template cache if needed
- Verify your edition supports the template (`whitelabel_standard` or `whitelabel_pro`)

### Content Breaks Layout
- Avoid extremely wide content (> 1200px)
- Test responsive behavior on mobile devices
- Don't override core dashboard CSS classes

## See Also

- [Builder Overview](docs/builder/overview)
- [Builder Admin UI](docs/builder/admin)
