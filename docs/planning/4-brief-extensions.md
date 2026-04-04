## Project Brief: Extensions

**Goal**
Build the architecture that allows first and third-party developers to extend T3 with new functionality. This is a platform bet — the quality of this foundation determines whether a marketplace ecosystem is possible. Get the architecture right before building on top of it, because Site Builder will be the first real consumer of it.

**Constraints**
- Extensions must not be able to touch `tcms/` core files — sandboxed to their own directory
- An extension that breaks must not take down the whole T3 instance
- Architecture must support both free and paid extensions
- Extensions distributed through a T3-hosted marketplace but installable via CLI or admin dashboard
- Composer under the hood, one-click install in the UI

**Directory structure**
```
tcms-data/
    extensions/
        vendor-name/
            extension-name/
                extension.json     # manifest
                Extension.php      # service provider / entry point
                src/
                templates/         # Twig templates
                assets/
```

---

### What an Extension Can Do

Extensions register capabilities through a clean service provider interface. The permitted extension points are:

- **Custom field types** — new form fields in the admin (e.g. a map picker, a color swatch selector)
- **Custom admin sections** — new pages/sections added to the T3 admin nav
- **Custom Twig functions and filters** — extend the templating layer
- **CLI commands** — register new `tcms` commands
- **API endpoints** — add new REST routes
- **Webhooks** — react to T3 content events (object saved, deleted, etc.)
- **Dashboard widgets** — add panels to the admin home screen

---

### The Manifest (`extension.json`)

```json
{
    "id": "joeworkman/seo",
    "name": "SEO Pro",
    "version": "1.0.0",
    "requires": "totalcms>=3.5.0",
    "entry": "Extension.php",
    "permissions": [
        "admin:sections",
        "twig:functions",
        "api:routes",
        "cli:commands"
    ]
}
```

Permissions are declared upfront and shown to the user before install. An extension that tries to do something not declared in its manifest is blocked.

---

### The Service Provider Pattern

Every extension implements a single interface:

```php
interface ExtensionInterface
{
    public function register(Container $container): void;
    public function boot(T3Application $app): void;
}
```

`register()` binds services into the container. `boot()` registers routes, Twig extensions, CLI commands, admin sections. This mirrors how Slim 4 and Laravel handle service providers — it's a pattern senior PHP developers will recognize immediately.

---

### Marketplace

**Discovery and distribution via `marketplace.totalcms.co`**

Each listing includes: description, version history, required T3 version, permissions requested, price, developer info, and ratings.

**Install paths:**
```bash
# Via CLI
tcms extension:install joeworkman/seo
tcms extension:list
tcms extension:update joeworkman/seo
tcms extension:disable joeworkman/seo

# Via admin dashboard
# One-click install from marketplace browser built into admin
```

**Economics:**
- T3 takes 25% of paid extension revenue
- Free extensions listed at no cost
- Developer accounts managed via `marketplace.totalcms.co`
- License validation handled server-side so paid extensions can't be pirated by copying files

**First-party extensions you build set the quality bar:**
- SEO Pro
- Ecommerce
- Analytics
- Site Builder (yes — Site Builder ships as a first-party extension, not core)

---

### Safety and Isolation

- Extensions loaded inside a try/catch — a broken extension logs an error and is skipped, T3 continues
- Extensions can be disabled via admin or CLI without uninstalling
- Extension files are never auto-updated without explicit user action
- Security review process for marketplace submissions (at minimum automated static analysis)

---

### What Done Looks Like

- A minimal "Hello World" extension can be built following documentation and loaded into T3 without touching core files
- Extension registers a custom Twig function that works in templates
- Extension registers a CLI command that appears in `php tcms`
- Extension registers an admin section that appears in the nav
- `tcms extension:install` successfully downloads and activates an extension from the marketplace
- A disabled extension does not affect T3 operation
- Paid extension license validation works correctly

---

One important flag before Claude Code starts this project: **the extension API surface is a public contract.** Once third-party developers build against it, breaking changes are very costly. It's worth taking extra time on the interface design before writing implementation code — get the `ExtensionInterface` and manifest format right first, then build the loader around them.
