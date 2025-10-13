# White Label Dashboard

**Available in:** Total CMS Pro only

The white label feature allows you to customize the Total CMS admin dashboard with your own branding, making it appear as your own custom CMS solution for clients.

## Overview

White label templates let you inject custom content into specific areas of the admin dashboard, including custom logos, login page content, download authentication pages, and the admin home page.

## Available Templates

Total CMS provides six white label template locations:

| Template File | Location | Description |
|--------------|----------|-------------|
| `whitelabel/login-above.twig` | Login page | Content displayed above the login form |
| `whitelabel/login-below.twig` | Login page | Content displayed below the login form |
| `whitelabel/download-auth-above.twig` | Download auth page | Content displayed above the download authentication form |
| `whitelabel/download-auth-below.twig` | Download auth page | Content displayed below the download authentication form |
| `whitelabel/admin-logo.twig` | Admin header | Custom logo replacing the Total CMS logo |
| `whitelabel/admin-home.twig` | Admin home page | Custom content for the dashboard home page |

## Setup

### 1. Create Templates Directory

You can create the `whitelabel` directory in two ways:

**Option A: Using the Dashboard (Recommended)**

1. Navigate to `/admin/templates` in the Total CMS dashboard
2. Click "New Folder"
3. Name it `whitelabel`
4. Create your template files inside this folder using the dashboard's template editor

**Option B: Manually**

Create a `whitelabel` directory inside your templates directory:

```
tcms-data/
  templates/
    whitelabel/
      admin-logo.twig
      admin-home.twig
      login-above.twig
      (etc.)
```

### 2. Create Your Templates

Only create the templates you want to customize. If a white label template doesn't exist, Total CMS will use its default content.

You can create templates either through the dashboard's template manager at `/admin/templates` or by manually creating `.twig` files in the `whitelabel` folder.

## Template Examples

### Custom Logo

**File:** `whitelabel/admin-logo.twig`

The custom logo must be wrapped in a link with the class `custom-logo`:

```twig
<a class="custom-logo" href="/admin" title="Dashboard Home">
	<img src="/path/to/your-logo.png" alt="Your Company" style="max-height: 32px;">
</a>
```

Or with an SVG:

```twig
<a class="custom-logo" href="/admin" title="Dashboard Home">
	<svg width="24" height="24" viewBox="0 0 24 24">
		<!-- Your SVG content -->
	</svg>
</a>
```

**Important:** The `<a class="custom-logo">` wrapper is required for proper styling and layout.

### Login Page - Above Form

**File:** `whitelabel/login-above.twig`

```twig
<div class="login-branding">
	<img src="/images/company-logo.png" alt="Your Company" style="max-width: 200px; margin-bottom: 2rem;">
	<h2>Client Portal Login</h2>
	<p>Welcome to your content management system.</p>
</div>
```

### Login Page - Below Form

**File:** `whitelabel/login-below.twig`

```twig
<div class="login-footer">
	<p>Need help? Contact us at <a href="mailto:support@yourcompany.com">support@yourcompany.com</a></p>
	<p class="copyright">&copy; {{ "now"|date("Y") }} Your Company. All rights reserved.</p>
</div>
```

### Download Authentication - Above Form

**File:** `whitelabel/download-auth-above.twig`

```twig
<div class="download-header">
	<h2>Secure File Access</h2>
	<p>Enter your email address to access this file.</p>
</div>
```

### Download Authentication - Below Form

**File:** `whitelabel/download-auth-below.twig`

```twig
<div class="download-footer">
	<p class="help-text">
		Files are protected and require authentication.
		If you need assistance, please contact support.
	</p>
</div>
```

### Admin Home Page

**File:** `whitelabel/admin-home.twig`

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
				<li><a href="/admin/media">Media Library</a></li>
			</ul>
		</div>

		<div class="dashboard-card">
			<h3>Resources</h3>
			<ul>
				<li><a href="https://yourcompany.com/docs" target="_blank">Documentation</a></li>
				<li><a href="https://yourcompany.com/support" target="_blank">Support</a></li>
				<li><a href="https://yourcompany.com/training" target="_blank">Training Videos</a></li>
			</ul>
		</div>

		<div class="dashboard-card">
			<h3>Need Help?</h3>
			<p>Contact our support team:</p>
			<p><strong>Email:</strong> support@yourcompany.com<br>
			<strong>Phone:</strong> (555) 123-4567</p>
		</div>
	</div>
</div>

<style>
.custom-dashboard {
	padding: 2rem;
}

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

.dashboard-card ul {
	list-style: none;
	padding: 0;
}

.dashboard-card li {
	margin: 0.5rem 0;
}

.dashboard-card a {
	color: oklch(var(--totalform-accent));
	text-decoration: none;
}

.dashboard-card a:hover {
	text-decoration: underline;
}
</style>
```

## Available Variables

All white label templates have access to standard Twig functionality and Total CMS globals:

- `{{ cms.config('key') }}` - Access configuration values
- `{{ cms.env }}` - Current environment (development, production, etc.)
- `{{ "now"|date("Y") }}` - Current year (useful for copyright notices)
- All standard Twig filters and functions

## Styling

White label content inherits the admin dashboard's CSS variables for consistent theming:

```css
/* Common CSS variables available */
--totalform-accent         /* Primary accent color */
--totalform-border-color   /* Border colors */
--totalform-text-color     /* Text color */
--totalform-bg             /* Background color */
--totalform-radius         /* Border radius */
--totalform-nearwhite      /* Light background */
--totalform-darkgray       /* Dark text */
```

Example usage in inline styles:

```twig
<div style="border: 1px solid oklch(var(--totalform-border-color)); border-radius: var(--totalform-radius);">
	<!-- Content -->
</div>
```

## Best Practices

### 1. Keep It Simple
Don't overcomplicate white label templates. Focus on essential branding elements.

### 2. Test on Multiple Devices
Ensure your white label content looks good on desktop, tablet, and mobile devices.

### 3. Use Relative Paths
When linking to assets, use relative paths or absolute URLs:

```twig
{# Good #}
<img src="/images/logo.png" alt="Logo">
<img src="https://yourcdn.com/logo.png" alt="Logo">

{# Avoid #}
<img src="images/logo.png" alt="Logo">
```

### 4. Maintain Accessibility
Include proper alt text for images, use semantic HTML, and ensure sufficient color contrast.

### 5. Consider Client Updates
Remember that your white label templates will persist across Total CMS updates, so avoid relying on undocumented features.

## Logo Guidelines

### Custom Logo Requirements

- **Wrapper Required:** Must be wrapped in `<a class="custom-logo">`
- **Recommended Height:** 30-50px for optimal display
- **Format:** PNG, SVG, or JPG
- **Background:** Transparent backgrounds work best
- **File Size:** Keep under 100KB for fast loading

### Logo Example with Proper Sizing

```twig
<a class="custom-logo" href="/admin" title="Dashboard Home">
	<img
		src="/images/client-logo.svg"
		alt="Client Name"
		style="height: 40px; width: auto;"
	>
</a>
```

## Disabling White Label

To disable white label customization, simply delete or rename the white label template files. Total CMS will automatically revert to default content.

## Troubleshooting

### Logo Not Displaying
- Verify the `<a class="custom-logo">` wrapper is present
- Check that the image path is correct
- Ensure the image file exists and is accessible
- Check browser console for 404 errors

### Styles Not Applied
- Ensure you're using CSS variables correctly: `oklch(var(--variable-name))`
- Check for typos in CSS variable names
- Verify inline styles use proper syntax

### Template Not Loading
- Confirm template file is in `resources/templates/whitelabel/` directory
- Check file naming matches exactly (case-sensitive)
- Verify file extension is `.twig`
- Clear Twig template cache if needed

### Content Breaks Layout
- Avoid extremely wide content (> 1200px)
- Test responsive behavior on mobile devices
- Use CSS Grid or Flexbox for responsive layouts
- Don't override core dashboard CSS classes

## Security Considerations

White label templates are rendered server-side with Twig. Follow these security guidelines:

1. **Never Include User Input:** Don't render unsanitized user-provided content
2. **Escape Output:** Use Twig's auto-escaping (enabled by default)
3. **Validate External Resources:** Only link to trusted external resources
4. **Avoid JavaScript:** Keep templates simple; avoid complex JavaScript
5. **Test Thoroughly:** Review all custom content before deploying to production

## License Enforcement

White label features are **Pro only**. If a user downgrades from Pro to a lower edition:

- White label templates will be ignored
- Default Total CMS branding will be restored
- Template files remain in place (not deleted)
- Upgrading to Pro will automatically re-enable white label features

---

For questions or assistance with white labeling, please refer to the Total CMS documentation or contact support.
