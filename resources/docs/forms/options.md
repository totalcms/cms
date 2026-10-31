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
    fieldIcons: true,              # Render the icon slot beside each field; a field's own `icon` option overrides (bool, default: true)
    addOnly: true,                 # Security: Only allow creating new objects, never editing (bool, default: false)
    register: true,                # Retarget the form at /admin/register/{collection} so the new user is also auto-logged-in (bool, default: false)
    verifyRedirect: '/welcome',    # Register mode with email verification: where the verification link lands instead of the admin login (string, default: '')
    botProtection: true,           # Add a honeypot and a time trap that turn away naive bots on register and public create forms (bool, default: false)
}) }}
```

## Security: Add Only Forms

Use `addOnly: true` for forms on the public side of your website to prevent users from editing existing objects by manipulating URL parameters:

```twig
{# Public form - secure against ID manipulation #}
{% set form = cms.form.builder('inquiries', {
    addOnly: true,
    newActions: [
        {
            action: 'message',
            text: 'Thanks — we\'ll be in touch.'
        }
    ]
}) %}
{% do form.addField('name') %}
{% do form.addField('email') %}
{% do form.addField('message', {field: 'textarea'}) %}
{{ form.build() }}
```

**Security Note:** When `addOnly` is enabled:
- Any ID parameter in the URL or form is ignored
- The form will always create a new object, never update existing ones
- Protects against malicious users passing `?id=123` to edit other users' data

## Member Portals: Owned Collections

`addOnly` stops a visitor editing anything. When signed-in members should edit *their own* submissions and nothing else, do not rely on the form hiding other people's ids — the form posts to the REST API, which decides on access groups alone. Instead make the collection **owned**:

1. Add an `owner` text property to the schema, list it in the schema's [`index`](docs/schemas/reference#index) array, and set the collection's **Owner Property** to it ([`ownerProperty`](docs/collections/settings#ownerproperty)). Members' lists are scoped by filtering the index on `owner`; if it is not indexed, every member's list comes back empty.
2. Grant the members' access group `create`, `read`, `update` (and `delete` if they may withdraw) on the collection.
3. Mark the moderation fields [`protected`](docs/schemas/validation#protected): `"draft": {"protected": {"value": true}}` sends every member edit back to review.

The server then stamps `owner` on create and refuses any API read or write of someone else's object. The template, though, renders on the server with its own access to the collection: `cms.object.get()` loads any object you name, whoever is signed in. So check the owner before building the edit form:

```twig
{% set user = cms.auth.userData() %}
{% set site = cms.object.get('showcase', app.request.query.get('id')) %}
{% if user and site and site.owner == user.id %}
    {% set form = cms.form.builder('showcase', {
        id: site.id,
        method: 'PATCH',
        actions: [{ action: 'message', text: 'Saved — your changes are back in the review queue.' }]
    }) %}
    {% do form.addField('name') %}
    {% do form.addField('url') %}
    {{ form.build() }}
{% else %}
    <p>That submission is not yours to edit.</p>
{% endif %}
```

Use `method: 'PATCH'` on an edit form that shows only some of the fields. An edit form saves with `PUT` by default, which replaces the whole object, so every field the form leaves out is cleared. The owner and protected fields are kept either way (the server puts back what is stored), but `description`, images and anything else not on the form would be lost.

The `if` is a belt-and-braces measure: `cms.form.builder()` itself refuses to load another member's object, rendering only "You do not have permission to edit this object." in place of the whole form (no fields, no save button), and the API returns `403` for the save. The deck item forms, `cms.form.deck()` and `cms.form.deckBuilder()`, refuse the same way when `id` names another member's object, whether they would edit one of its items (`itemId`) or add one. Keep the check in the template anyway so the page around the form (headings, other `cms.objects` calls) does not show the object either. Members are also refused the whole-collection routes beyond create, list and query (export, report, import, reindex, bulk delete and clone), so a portal cannot be used to pull the full collection.

## Public Registration Forms

For user-registration forms specifically, prefer `register: true` over plain `addOnly`. It retargets the form at the public registration endpoint (`POST /admin/register/{collection}`), which creates the user record AND auto-logs them in as part of the same request — so the visitor doesn't have to immediately type the credentials they just submitted.

```twig
{# Public sign-up form - creates the user and signs them in #}
{% set form = cms.form.builder('members', {
    register: true,
    newActions: [
        {
            action: 'redirect',
            link: '/welcome'
        }
    ]
}) %}
{% do form.addField('name') %}
{% do form.addField('email') %}
{% do form.addField('password', {field: 'password'}) %}
{{ form.build() }}
```

**Behaviour:**
- Form posts to `/admin/register/{collection}` instead of `/api/collections/{collection}`
- Whether the new user is auto-logged in OR sent a verification email depends on the collection's **Require Email Verification** setting (see below)
- `addOnly` is implied — the register endpoint only handles POST, no edit/update path exists
- Image and file fields work the same way they do on any add-only form: uploads are deferred client-side until the parent save completes, then uploaded against the freshly-created user record

**Required server-side opt-in:**

Switch on **Allow Public Registration** in the **Public Registration** section of the target collection's settings; it is offered on collections whose schema is `auth` or inherits it. Nothing is open by default, so the operator-only `auth` collection is never exposed to public signups by accident. Without it the endpoint returns `403 Forbidden` ("Public registration is not enabled for this collection."). See [`publicRegistration`](docs/collections/settings#publicregistration).

**Registrant groups:**

A registrant receives the global `publicRegistrationGroup` (auth settings) plus the collection's **Registration Groups**, with duplicates removed. `admin` is never granted from Registration Groups. A `groups` value submitted in the form is always ignored.

A registrant who ends up in no group can sign in and see pages gated to signed-in users, but can't read or change any collection through the API. Give them a group here if your front-end forms need that. See [Users without a group](docs/auth/access-groups#users-without-a-group).

### Email Verification

Per-collection, you can require new registrants to confirm their email before their account is activated. Enable **Require Email Verification** on the collection's settings page (in the Public Registration section, once **Allow Public Registration** is on).

When enabled:

1. The registration save creates the user with `active = false`
2. A verification email is sent containing a tokenized link (default 24h expiry)
3. The response JSON carries `meta.requiresVerification: true`; the form builder hides the form and reveals any element marked `data-verification-message`:

   ```twig
   {% set form = cms.form.builder('members', {register: true}) %}
   {% do form.addField('email') %}
   {% do form.addField('password', {field: 'password'}) %}
   {{ form.build() }}

   <div data-verification-message hidden>
       <h3>Check your email</h3>
       <p>We've sent you a link to verify your account.</p>
   </div>
   ```

4. The form also dispatches a `cms:form:verification-required` event for custom JS (analytics, redirects, etc.)
5. Clicking the link hits `GET /admin/verify-email/{token}`, flips `active = true`, and redirects to the login page (or to your own page — see below)
6. If the link expired, the user is redirected to `/admin/resend-verification` where they can request a fresh link

#### Landing on your own page after verification

By default the verification link ends on the admin login page. To send members to a page on your site instead, set `verifyRedirect` on the registration form:

```twig
{{ cms.form.builder('users', {register: true, verifyRedirect: '/login'}) }}
```

The form posts the target as a hidden field. It travels on the verification token only — it is never saved on the user record — so the link in the email lands on `/login` once the account is active. If the link has expired, the target rides along to the resend form, and the fresh link lands there too.

The target must be on your own site: a path such as `/login` or `/members/welcome`, or a full `http(s)` URL on the same host as the form. Anything else (another domain, `//host`) is dropped and the link lands on the admin login page as before.

The verification sets a success flash message. Your landing page should show it, since the visitor arrives expecting confirmation:

```twig
{% for message in flash.get('success') %}
    <p class="cms-twig-info" role="alert">{{ message }}</p>
{% endfor %}
```

A "Resend verification email" link appears below the login form when a sign-in fails because the account is not yet active.

Default behavior (when the toggle is **off**) is unchanged: the new user is authenticated and signed in before the response returns — no verification step.

Verification-related config (in `config/tcms.php` under `auth`):

| Key | Default | Purpose |
|---|---|---|
| `verificationTokenExpiry` | `1440` (24h) | Minutes before a verification token expires |
| `verificationMailerId` | `''` | Optional custom mailer template ID; leave empty for the default Twig template at `email/verify-email.twig` |

**Security caveats the operator owns:**

- Anyone who reaches the registration endpoint can create a user record. Verification stops a bot from logging in, but it doesn't stop them from filling the user table with junk records — turn on [Bot Protection](#bot-protection) even when verification is on.
- New users receive the global `publicRegistrationGroup` plus the collection's Registration Groups (never `admin`). If those groups reach gated content, every verified signup gains that access.
- Password validation (minimum length etc.) is the schema's responsibility, not the endpoint's.
- The verification flow assumes the auth schema has an `email` field. If your custom schema doesn't, the verification email cannot be sent — the account is still created (inactive) but stays in limbo unless an admin activates it manually.

### Bot Protection

`botProtection: true` adds two hidden fields to a register form or a public create form, with no CAPTCHA for visitors to solve:

```twig
{{ cms.form.builder('members', {register: true, botProtection: true}) }}
{{ cms.form.builder('enquiries', {addOnly: true, botProtection: true}) }}
```

- **A honeypot**: a text field moved off-screen and hidden from screen readers and the keyboard. A person never fills it; a bot that fills every field does.
- **A time trap**: a token holding the time the form was rendered, signed with the site's key, the collection and the form type. A submission sent less than 3 seconds after the form rendered, more than a day later, or with a forged token fails. A token from one form does not work on another.

A submission that fails is answered as if it succeeded, so the bot learns nothing: a register form gets the response a real signup gets (with `meta.requiresVerification: true` when the collection requires email verification) and a create form gets the usual saved-object response. Nothing is saved, no one is signed in and no email is sent. The access log records each one at info level with the reason (`honeypot`, `missing`, `too_fast`, `expired` or `bad_signature`).

The exception is an expired token: the form genuinely came from your site and was left open for more than a day, so the visitor gets a `422` with "This form has expired. Reload the page and try again."

Do not put a bot-protected form inside `{% cache %}`: a cached token expires and visitors see the reload message.

Signed-in visitors never see the fields and are never checked. With authentication disabled, public creates are not checked (the register endpoint still is).

The endpoints check only submissions that carry the fields, so a form without `botProtection` keeps working. To refuse posts that skip the check altogether, a bot posting straight to the endpoint, switch on **Require Bot Protection** in the collection's Access Controls ([`requireBotProtection`](docs/collections/settings#requirebotprotection)). The collection's register form, and its new-object forms when it allows public `create`, then include the fields without the option. A public clone is checked like a create, so on such a collection an anonymous clone is answered with a decoy and nothing is copied.

A form's mailer action sends the bot fields with its mail, and the mailer endpoint checks them too. A mailer template with **Require Bot Protection** on adds the fields to the create and register forms that send it, without the option. See [Bot protection for form mail](docs/notifications/mailer#bot-protection-for-form-mail).

Bot protection stops naive bots. A bot that loads the form in a real browser and waits gets through, so keep email verification on where accounts reach anything of value.

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
{% set form = cms.form.builder('products', {
    class: 'no-status-banner'
}) %}
{% do form.addField('title') %}
{% do form.addField('price') %}
{{ form.build() }}
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

## Disabling the Delete Confirmation

By default, deleting an object through a form shows a confirmation dialog before the object is removed. Some frontend workflows make that dialog pure friction — the classic example is a pin/unpin (favorite/unfavorite) feature, where each pin is an object and unpinning deletes it. A visitor toggling pins shouldn't have to confirm every click.

Add the `no-delete-confirm` class to the form to skip the dialog for its object delete:

```twig
{# Unpin deletes the pin object immediately — no confirmation dialog #}
{% set form = cms.form.builder('pins', {
    class: 'no-delete-confirm'
}) %}
```

The class is honored on the form element or any ancestor, so you can also set it on a wrapper that contains many pin forms.

**What it affects:**
- The object delete triggered by a form's delete action (`DELETE /collections/{collection}/{id}`) — including schema, collection, and template forms if you add the class to those.

**What it never affects:**
- Field-level deletes (images, files, gallery items, Depot) — those keep their confirmation.
- Bulk deletes in the admin always show a confirmation — the class is deliberately ignored there.
- Server-side authorization: this is a UX convenience only. Deletes are always authorized by the server regardless of any client-side dialog, so removing the dialog grants no additional capability.

**Tip:** for destructive-but-frequent flows you can also keep the dialog but remove the countdown wait by setting `data-confirm-countdown="0"` — see the confirm dialog's countdown options.

**Related:** the `no-change-listener` class on a field container similarly opts a field out of unsaved-change tracking (it won't mark the form `.unsaved` or trigger the leave-page warning).
