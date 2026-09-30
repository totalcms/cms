---
title: "Mailer"
description: "Create Twig email templates in Total CMS and send them from forms, password resets, automations, PHP, or as a bulk send to every object in a collection."
related:
  - collections/form-settings
  - forms/options
  - auth/password-reset
  - automations/handlers
  - operations/cron-urls
  - auth/access-groups
  - operations/licenses
---

# Mailer

The Mailer is Total CMS's email system. You write an email once as a **template** — recipient, subject, and body, all in Twig — and then send it from wherever you need it:

- a **form action**, after someone submits a form
- the **password reset** and **email verification** flows
- an **automation** handler, or its error notification
- your own **PHP** code
- a **bulk send** that mails every object in a collection, one email per object (Pro)

Templates are stored as objects in the reserved `mailer` collection, so they can be exported, imported, duplicated, and synced with `tcms push --mailer` like any other content. Email goes out over SMTP.

## Editions

| Feature | Edition |
|---|---|
| Mailer templates, form actions, and every other single send | Standard and above |
| Bulk Send | Pro and above |

On Lite, mailer form actions are removed from forms, and any attempt to send a template fails with *"Mailer actions require the Standard edition or higher"*. The built-in password reset and verification emails still send on Lite, as long as no template is picked for them in Settings → Auth. If one is picked, those emails fail on Lite, silently, because the auth flows never reveal whether an email went out. Clear the selection to go back to the built-in emails. See [Licenses](docs/operations/licenses) for the full edition matrix.

## Setting up SMTP

Before anything can send, point Total CMS at an SMTP server in **Settings → SMTP**.

| Setting | Key | Default | Notes |
|---|---|---|---|
| SMTP Host | `host` | `127.0.0.1` | Your mail server or provider's SMTP host |
| SMTP Port | `port` | `25` | Usually `587` for TLS or `465` for SSL |
| Encryption | `secure` | TLS | `tls` (STARTTLS), `ssl`, or none |
| SMTP Username / Password | `username`, `password` | — | Authentication is only used when a username is set |
| From Address | `from` | — | The default sender. Templates can override it |
| From Name | `fromName` | — | The default sender name |
| Send Delay (ms) | `sendDelay` | `0` | Milliseconds to wait between bulk emails |
| Max Emails Per Hour | `maxPerHour` | `0` | Bulk emails allowed per hour. `0` is unlimited |
| Max Emails Per Day | `maxPerDay` | `0` | Bulk emails allowed per 24 hours. `0` is unlimited |

You can also set these in `tcms.php` under `$settings['smtp']`, using the keys above. The page's **Default To Address** field isn't used by anything yet.

### Testing the connection

The **Test SMTP Configuration** section at the bottom of the SMTP settings page sends a plain test message to any address you enter. It uses the settings you have **saved**, so save first, then test. A successful test proves the server connection and credentials; it doesn't involve templates, editions, or the whitelist.

Most transactional providers (Postmark, Mailgun, Amazon SES, SendGrid, Fastmail, Google Workspace) offer SMTP credentials. Use them rather than your web host's local mail server — your emails are far more likely to be delivered.

## Mailer settings

**Settings → Mailer** controls who can receive email sent through the public send endpoint and how fast.

| Setting | Key | Default | What it does |
|---|---|---|---|
| Email Domain Whitelist | `whitelist` | *(off)* | A JSON list of allowed recipient domains, e.g. `["@yourcompany.com"]`. When set, a template can only be sent **to** an address at one of them |
| Rate Limit per IP | `ratePerIp` | `10` | Requests one IP address can make to `POST /api/action/mailer` per window (1–1000) |
| Rate Limit per Template | `ratePerTemplate` | `50` | Requests for any one template through that endpoint per window (1–10000) |
| Rate Limit Window (seconds) | `rateWindow` | `300` | The window length (60–3600) |

These can also be set in `tcms.php` under `$settings['mailer']`.

Each whitelist entry is one domain, matched exactly and without regard to case: `@yourcompany.com` allows `Jane@YourCompany.com` but not `jane@mail.yourcompany.com` — list subdomains separately. The whitelist checks the **To** address only; CC, BCC, and Reply-To are not checked. It applies to every template send, including bulk sends and their override address. It does not apply to the built-in auth emails or the SMTP test, which don't use templates.

The rate limits apply only to the public endpoint described [below](#sending-from-the-api), and they count requests, including ones that fail. The admin **Test Email** button uses the same endpoint, so a burst of testing can hit the limit. The counters are kept in the cache; if no cache backend is available, the limits are not enforced.

## Creating a template

Go to **Mailer** in the admin sidebar and click **+ New Email**. Templates are grouped in the sidebar by **Category**.

| Field | Required | Notes |
|---|---|---|
| Active | | Inactive templates refuse to send |
| Template Name | ✓ | Internal name. The ID is generated from it plus a short unique suffix |
| Category | | Groups templates in the sidebar |
| Description | | What the email is for |
| To Email | ✓ | Recipient address. Twig: `{{ data.email }}` |
| To Name | | Twig: `{{ data.name }}` |
| From Email / From Name | | Leave empty to use the SMTP defaults |
| Reply-To Email | | Twig: `{{ data.email }}` |
| CC Emails / BCC Emails | | One address per line. Each line is rendered with Twig, and empty results are dropped |
| Subject | ✓ | Twig: `New message from {{ data.name }}` |
| Body (HTML) | ✓ | The email body, in Twig |
| Body (Plain Text) | | Optional plain-text version for mail clients that don't show HTML |

The template's **ID** is what you reference everywhere else — in form actions, auth settings, automations, and code.

### Template variables

The address, name, subject, and body fields are rendered as Twig templates. From Name can use Twig too; From Email is an email field, so it only accepts a plain address. Active, Template Name, Category, and Description are never rendered. Two variables are available:

- **`data`** — the data the email is about. What it contains depends on where the email was sent from (see below).
- **`user`** — the signed-in user who triggered the send, when there is one.

Templates render in the same Twig environment as the rest of your site, so filters and the `cms` global work as usual.

```twig
<h1>New message from {{ data.name }}</h1>
<p>{{ data.message|nl2br }}</p>
<p>Sent {{ "now"|date("F j, Y") }}</p>
```

If a field fails to render — a Twig syntax error, for example — the error is written to `email.log` and the field is sent as its raw template text, so check the log if an email arrives with `{{ }}` in it.

| Sent from | `data` contains | `user` |
|---|---|---|
| Form action | The submitted form's field values | The signed-in user, if any |
| Bulk Send | The full object being mailed | — |
| Password reset | `email`, `name`, `user` (the account), `resetUrl`, `expiryMinutes`, `collection` | — |
| Email verification | `email`, `name`, `verifyUrl`, `expiryMinutes`, `collection` | — |
| Automation error | `automation`, `error` | — |
| Automations / PHP | The array you pass | The array you pass as the fourth argument, if any |

### Responsive layouts with Inky

Body HTML is run through [Inky](https://get.foundation/emails/docs/inky.html) before sending, so you can use Inky's tags for layouts that hold up across mail clients:

```html
<container>
  <row>
    <columns>
      <h1>Hi {{ data.name }}</h1>
      <p>Thanks for signing up.</p>
      <button href="{{ data.url }}">Get started</button>
    </columns>
  </row>
</container>
```

Inky is optional: plain HTML keeps its layout. The body is still parsed and re-serialized on the way through, though, so entities may come out in numeric form and a full `<html>` wrapper is added — check a test send if you rely on unusual markup. Inky converts markup only — it doesn't add CSS. Include your own styles inline, or in a `<style>` block for the clients that support one.

### Testing a template

The **Test Email** section on a template's edit page sends the template for real, using JSON you enter as `data`:

```json
{ "name": "Jane", "email": "jane@example.com", "message": "Hello!" }
```

**Load test data from object** fills the box from an existing object — enter its `collection/id`. The test data is remembered in your browser for each template. Remember that the email goes to whatever **To** renders to, so use your own address in the test data. Test sends go through the same endpoint as form actions, so the whitelist and rate limits apply, and `user` is you. Loading from an object needs read access to its collection.

Attachments are not supported.

## Sending from a form

Add a `mailer` action to a form and the email is sent after the form saves successfully:

```twig
{% set form = cms.form.builder('contact', {
    newActions: [
        { action: 'mailer', mailerId: 'contact-notify' }
    ]
}) %}
{% do form.addField('name') %}
{% do form.addField('email') %}
{% do form.addField('message') %}
{{ form.build() }}
```

Use `newActions` for new objects, `editActions` for edits, and `deleteActions` for deletes. Actions run in order; if an email fails, the remaining actions stop unless the action has `continue: true`. To send it from the admin's own add and edit forms for a collection, put the action in the collection's [form settings](docs/collections/form-settings) — those settings don't apply to forms you build on your site, which need the action in their own options. More on building forms in [Form Builder](docs/forms/builder) and on actions in [Form Options](docs/forms/options).

`data` in the template is the form's field values as submitted, and `user` is the signed-in user, if there is one.

A common pair of templates for a contact form: one to you, and one confirming receipt to the visitor.

```text
contact-notify    To: you@yourcompany.com       Reply To: {{ data.email }}
contact-confirm   To: {{ data.email }}          Subject: Thanks, {{ data.name }}
```

## Password reset and verification emails

Total CMS has built-in emails for password resets and email verification. To send your own design instead, create a template and select it in **Settings → Auth** as the **Password Reset Email Template** or **Email Verification Template** (`forgotPasswordMailerId` and `verificationMailerId`). Use `{{ data.resetUrl }}` or `{{ data.verifyUrl }}` for the link; the full variable lists are in the [table above](#template-variables) and in [Password Reset](docs/auth/password-reset). Custom auth templates need the Standard edition.

## Sending from automations and PHP

Automation handlers get the Mailer as `$ctx->mailer`:

```php
$ctx->mailer->sendEmail('weekly-digest', ['posts' => $posts->all()]);
```

Each automation can also name an **error mailer**, sent in production when a run fails, with `data.automation` and `data.error`. See [Automation Handlers](docs/automations/handlers).

In your own PHP, `$totalcms->mailer()` returns the same service ([PHP API](docs/apis/php-api)):

```php
$result = $totalcms->mailer()->sendEmail('order-confirmation', [
    'orderId' => 'order-123',
    'email'   => 'customer@example.com',
]);

if (!$result->success) {
    // $result->message and $result->error explain why
}
```

`sendEmail()` also takes two optional arguments: an address to send to instead of the template's **To**, and an array exposed to the template as `user`:

```php
$totalcms->mailer()->sendEmail('welcome', $data, null, ['name' => 'Jane']);
```

## Sending from the API

Form actions send through a public endpoint:

```http
POST /api/action/mailer
Content-Type: application/json

{ "mailerId": "contact-notify", "data": { "name": "Jane", "email": "jane@example.com" } }
```

It returns `{"success": true, "message": "..."}`. It responds with `400` for a missing `mailerId` or malformed `data`, `500` when the send fails, and `429` with a `Retry-After` header when a [rate limit](#mailer-settings) is hit.

> **This endpoint is public by design** — a visitor's browser calls it after submitting your contact form. That means anyone can call it with any template ID and any `data`. If a template's **To**, **CC**, or **BCC** comes from `data` (like `{{ data.email }}`), anyone can use that template to send your email to any address. Keep the recipient fixed for notification templates, keep the rate limits tight, and use the whitelist when a template only ever needs to reach your own domain. Deactivate templates you aren't using.

## Bulk Send

*Pro edition.* Bulk Send mails a template to every object in a collection — a newsletter to your subscribers, a notice to every member, a reminder to everyone registered for an event. Each object becomes the `data` for one email, so `{{ data.email }}` and `{{ data.name }}` are that object's fields.

Bulk Send appears at the bottom of a template's edit page, once the template has been saved.

### Audience & Send

| Field | What it does |
|---|---|
| Collection | The collection to send to |
| Include Filter / Exclude Filter | [Index filters](docs/apis/index-filter) to narrow the audience, e.g. include `subscribed:true`, exclude `status:bounced` |
| Specific Objects | Pick individual objects instead. When any are picked, the filters are ignored |
| Override To Email | Send every email to this address instead — for proofing (see below) |
| Schedule | Send at a later date and time, in your site's timezone. Leave empty to send on the next queue run |

Click **Queue Bulk Send** and confirm. One email job is queued per object, and the result shows how many were queued. These choices apply to this send only; they aren't saved with the template.

### Preview

Pick an object under **Preview** to see the subject, recipient, and HTML body that object would get, without sending anything. Choose a Collection under Audience & Send first. The preview shows Body HTML before Inky runs, so layout tags display as-is, and it doesn't show CC, BCC, or the plain-text body.

### How sends are processed

Queuing doesn't send anything by itself. Emails go out as the job queue runs, from the `tcms jobs:process` cron job, or the `/cron/jobs` URL on hosts without cron (see [Cron URLs](docs/operations/cron-urls)). A site with no job runner set up will queue emails forever.

Each email job:

1. Checks the hourly and daily limits (**Max Emails Per Hour** and **Max Emails Per Day** in SMTP settings). If a limit is reached, the job goes back in the queue for a later run — it isn't failed, and it doesn't use up an attempt. The rest of that run stops too, so other queued jobs, including non-email ones, wait with it.
2. Checks the once-per-object rule below.
3. Waits **Send Delay** milliseconds, if set, to spread emails out.
4. Sends the email. A failed send gets three attempts in all. Failed jobs are retried only by `tcms jobs:process`; the `/cron/jobs` URL doesn't retry them.

The limits count every bulk email sent, test sends included. Check your email provider's sending limits and set yours below them. Most providers throttle or suspend accounts that exceed their limits.

### Each template is sent to each object once

**A template is delivered to each object only once — ever.** When you queue a bulk send, objects that already received that template are left out, and the result tells you how many: *"Queued 12 emails for sending (40 left out: already received this email)"*. If every object has already received it, nothing is queued and you get an error that says so.

This makes a bulk send safe to repeat. If a send stopped partway, or new subscribers joined since, queuing it again reaches only the objects that were missed.

It also means **a recurring email needs a new template each time.** For a monthly newsletter, **Duplicate** last month's template (from the template's actions menu), edit it, and send the copy. The copy gets a new ID, so everyone receives it (unless **Keep ID on Duplicate Object?** is turned on in Settings → Dashboard — then change the ID yourself).

### Proofing with Override To

To see exactly what your audience will get, fill in **Override To Email** with your own address and queue the send. Every object's email — rendered with that object's data — goes to you instead.

Test sends never count as delivered. When you clear the override and queue the real send, everyone receives it. You can proof as many times as you like, but you get one email per object, and they count toward the hourly and daily limits — for a quick proof of a large audience, pick a few **Specific Objects**.

The override replaces **To** only. If the template has **CC** or **BCC** addresses, they still receive every test email, so clear them while proofing a large audience.

### Send History

**Send History** on the template's edit page lists the template's recent batches:

| Column | Meaning |
|---|---|
| Queued | When the batch was queued |
| Audience | The collection; a **Test** badge and address for override sends; how many objects were left out as already sent |
| Status | *Scheduled*, *In progress*, *Complete*, *Failures*, or *Nothing sent* |
| Sent / Failed / Skipped | Emails processed so far, counting each object once |
| Pending | Queued emails not yet sent, failed, or skipped |

The panel shows the 10 most recent batches and refreshes after each queue; click **Refresh** to update it while a batch is sending. *Skipped* counts objects that received the email from another batch after this one was queued — two overlapping sends of the same template, for example. A failed email is one that couldn't be sent, or whose object was deleted after the send was queued; `email.log` in your logs folder has the reason.

The history is stored in `tcms-data/.system/bulkmailer`, a SQLite database. Deleting that file erases the send history and resets the hourly and daily counts — and **every object becomes eligible to receive every template again**.

Delivery is tracked by template and object ID. If you send the same template to a second collection, any object whose ID matches one already mailed from the first collection is left out; use a separate template for each collection.

### Bulk Send endpoints

The admin page uses these endpoints. They need the Pro edition and either a signed-in user whose access group has the `mailer` permission, or an API key whose grants include these paths.

| Endpoint | Purpose |
|---|---|
| `POST /api/action/mailer/bulk` | Queue a bulk send. Form fields: `mailerId`, `bulkCollection`, `bulkInclude`, `bulkExclude`, `bulkObjectIds[]`, `bulkOverrideTo`, `bulkscheduledAt` |
| `POST /api/action/mailer/bulk/preview` | Render one object's email: `mailerId`, `bulkPreviewObjectId`, `bulkCollection` |
| `GET /api/action/mailer/bulk/objects` | List a collection's objects for the picker: `bulkCollection`, `bulkInclude`, `bulkExclude` |
| `GET /api/action/mailer/bulk/history` | Recent batches for `mailerId` |

These return HTML fragments for the admin UI (except `objects`, which returns JSON), not a stable API. Errors from the endpoint itself also come back as HTML with status 200; only a refused sign-in or permission returns a 401 or 403.

## Permissions

The **Mailer** page and the Bulk Send endpoints require the `mailer` permission in the user's [access group](docs/auth/access-groups). The built-in Admin and Editor groups have it; Viewer and Default don't. Admins always have access.

Templates are also ordinary objects in the `mailer` collection, so the collection permissions of an access group apply to reading and editing them through the REST API.

## Troubleshooting

**Nothing arrives, and there's no error.** Check `email.log` first. Then check spam folders, and that the **From** address is one your SMTP provider allows you to send as — many providers reject or silently drop mail from unverified senders.

**The SMTP test works but a template doesn't send.** Check that the template is **Active**, that your edition is Standard or higher, and that the rendered **To** is a valid address that passes the whitelist, if you set one. A `429` means the rate limit was hit. The JSON response's `error` field and `email.log` have the details.

**Bulk emails are queued but never sent.** The job queue isn't running. Set up the `tcms jobs:process` cron job or the `/cron/jobs` URL. The **Job Queue Manager** under Utils shows what’s waiting.

**A bulk send stops partway and resumes later.** Either the hourly or daily limit was reached, or, on the `/cron/jobs` URL, the run used up its time budget. Both pick up again on the next run.

**"Nothing to send: all N matching objects have already received this email."** Each template reaches each object once. Duplicate the template to send the email again.

**An email shows `{{ data.something }}` literally.** The field failed to render. `email.log` has the Twig error.

**Emails go out without encryption.** Set **Encryption** to TLS or SSL in SMTP settings and match the port — `587` for TLS or `465` for SSL.
