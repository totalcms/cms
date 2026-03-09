---
title: "Templates"
description: "Create reusable Twig templates in Total CMS, organize them in folders, and use the Template Designer for seamless development-to-production sync."
---

# Templates

Templates are reusable Twig files stored in your `tcms-data/templates/` directory. They can be used with `{% include %}`, the `{% cmsgrid %}` tag, or rendered directly via the API.

## Managing Templates

Templates are managed through the admin dashboard at `/admin/templates`. From there you can create, edit, duplicate, and delete templates.

### Template IDs

Each template has a unique ID that can include forward slashes to organize into folders:

- `header` — root-level template
- `grids/blog` — template in the `grids` folder
- `pages/products/tile` — nested folders are supported

The `.twig` extension is added automatically — you only need to provide the ID.

### Using Templates

Include a template in any Twig file:

```twig
{% include 'header.twig' %}
{% include 'grids/blog.twig' %}
```

Use with `{% cmsgrid %}` for collection layouts:

```twig
{% cmsgrid cms.collection.objects('blog') from 'blog' with 'blog grid' %}
    {% include 'grids/blog.twig' %}
{% endcmsgrid %}
```

Render directly via the API:

```http
GET /templates/grids/blog
```

## White Label Templates

Special templates customize the admin interface. See [White Label](docs/admin/whitelabel) for details.

| Template | Purpose |
|----------|---------|
| `whitelabel/login-above.twig` | Content above the login form |
| `whitelabel/login-below.twig` | Content below the login form |
| `whitelabel/download-auth-above.twig` | Content above download auth form |
| `whitelabel/download-auth-below.twig` | Content below download auth form |
| `whitelabel/admin-home.twig` | Custom admin home page content |

---

## Template Designer

The Template Designer enables remote template updates from development tools like [Stacks](https://www.yourhead.com/stacks/). When building layouts in a visual GUI, the Template Designer automatically syncs the template content to both your local dev server and your production server — no manual copy-paste required.

### How It Works

1. **Create a template** in the admin dashboard and enable the Template Designer toggle
2. **Copy the Designer Token** shown in the template form
3. **Use the `{% templatedesigner %}` tag** in your development Twig template
4. **Every page load** on your dev server syncs the template content locally and pushes it to production

### Enabling Designer on a Template

Edit any template in the admin dashboard. You'll see two fields:

- **Template Designer** — Toggle to enable remote updates for this template
- **Designer Token** — Auto-generated token for authentication (visible when Designer is enabled)

> The Designer Token is read-only and generated automatically. Share it with your development tool to authorize updates.

### The `{% templatedesigner %}` Tag

This tag captures raw template content before Twig compiles it, preserving expressions like `{{ object.title }}` as source code rather than evaluating them.

#### Syntax

```twig
{% templatedesigner for 'TEMPLATE_PATH' on 'PRODUCTION_URL' token 'TOKEN' %}
    ...template content...
{% endtemplatedesigner %}
```

#### Parameters

| Parameter | Required | Description |
|-----------|----------|-------------|
| `for 'path'` | Yes | Template ID (without `.twig` extension) |
| `on 'url'` | Yes | Production API base URL |
| `token 'token'` | No | Designer token for remote sync (required for production sync) |

#### Example

```twig
{% templatedesigner for 'products/tile' on 'https://example.com/tcms/' token 'abc123def456' %}
    <article class="product-tile">
        <h3>{{ object.title }}</h3>
        <p>{{ object.summary }}</p>
        {% if object.image %}
            {{ cms.render.image(object, {w: 400}, {collection: 'products', property: 'image'}) }}
        {% endif %}
        <span class="price">{{ object.price | price }}</span>
    </article>
{% endtemplatedesigner %}
```

### Sync Behavior

The sync behavior depends on which server renders the page:

#### On your development server

When the page is rendered on a dev server (where the API URL differs from the `on` parameter):

1. **Local sync** — The template content is saved to the local `tcms-data/templates/` directory
2. **Remote sync** — A PUT request is sent to the production server's Designer API to update the template there
3. **Badge** — A status badge appears in the bottom-right corner showing sync results

The badge displays:
- The template path being synced
- Local sync status (success or error)
- Remote sync status (success, error, or skipped if no token provided)
- A **Copy Template** button to copy the raw template source to your clipboard

#### On your production server

When the API URL matches the `on` parameter, the tag is a no-op — no sync occurs, no badge is displayed, and there is zero performance overhead.

### Designer API

The Designer API provides a public endpoint for remote template updates. This endpoint is authenticated with the Designer Token, not the normal session/API key auth.

#### Update Template

```http
PUT /designer/templates/{path}
X-Designer-Token: your-token-here
Content-Type: text/plain

<article>{{ object.title }}</article>
```

The request body is the raw template content (plain text, not JSON). Only the template content is updated — the Designer Token and other metadata remain unchanged.

Returns `200` with:
```json
{
    "success": true,
    "template": "products/tile"
}
```

> The token can also be passed as a query parameter: `/designer/templates/path?token=your-token`

### Multiple Designer Blocks

A single page can contain multiple `{% templatedesigner %}` blocks. Each one syncs independently and gets its own entry in the status badge:

```twig
{% templatedesigner for 'products/card' on 'https://example.com/' token 'token1' %}
    <div class="card">{{ object.title }}</div>
{% endtemplatedesigner %}

<div class="divider"></div>

{% templatedesigner for 'products/detail' on 'https://example.com/' token 'token2' %}
    <section class="detail">
        <h1>{{ object.title }}</h1>
        <div>{{ object.content | markdown }}</div>
    </section>
{% endtemplatedesigner %}
```

### Local-Only Mode

If you omit the `token` parameter, the template is only saved locally — no remote sync is attempted:

```twig
{% templatedesigner for 'dev/prototype' on 'https://example.com/' %}
    <div class="prototype">{{ object.title }}</div>
{% endtemplatedesigner %}
```

This is useful when prototyping templates that aren't ready for production yet.

### Security

- Designer API endpoints are **public** (no session or API key required) but gated by the Designer Token
- Each template has its own unique token — compromising one token does not affect other templates
- Tokens are auto-generated UUIDs and cannot be edited manually
- The Designer toggle must be explicitly enabled per template
- The API only allows updating template **content** — metadata (token, enabled status) cannot be changed via the Designer API
