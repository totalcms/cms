---
title: "Public Uploads"
description: "Let visitors without an account send files to a depot from a public page with cms.form.depotDrop: the publicUpload setting, file types, size limits, comments, rate limits, and what to know before you turn it on."
related:
  - fields/file-depot
  - forms/overview
  - forms/options
  - automations/handlers
  - automations/triggers
  - collections/settings
  - operations/security
---

# Public Uploads

A public upload form lets a visitor with no account drop files into a depot on your site. The visitor sends files and sees nothing that is already there: no file list, no way to delete, rename or replace anything. You decide the file types, the sizes, and the folder the files land in.

Use it for a client sending photos, a job application with a resume, or a drop box for a form submission.

## Set it up

You need three things: a depot property with a `publicUpload` setting, an object that already exists, and the form on a page.

### 1. Add the setting to the depot property

Put `publicUpload` under the property's `settings`:

```json
{
	"depot": {
		"$ref"     : "https://www.totalcms.co/schemas/properties/depot.json",
		"label"    : "Incoming Files",
		"settings" : {
			"publicUpload" : {
				"extensions" : ["pdf", "jpg", "png"],
				"maxFileMb"  : 25,
				"maxTotalMb" : 500,
				"folder"     : "incoming",
				"comments"   : true
			}
		}
	}
}
```

| Key | Default | What it does |
|-----|---------|--------------|
| `extensions` | none | **Required.** The file extensions visitors may send, such as `["pdf", "jpg"]`. Without at least one, public uploads stay off |
| `maxFileMb` | `25` | The largest single file, in MB. Fractions work, such as `0.5` |
| `maxTotalMb` | `500` | The most the whole depot may hold. Once it is full, uploads are refused |
| `folder` | the depot root | The folder inside the depot where visitor files are stored. Visitors cannot choose another |
| `comments` | `false` | Shows a comment box below the drop area and saves the comment on each file |

MB here means MiB (1 MB is 1,048,576 bytes).

The collection's `publicOperations` do not change, and you do not open `update` or `read` to the public. No other property opens up, but every object in the collection does: anyone holding a form token from any page of that collection can upload to any of its objects. Use a dedicated collection for uploads, so that objects you never put a form on stay out of reach.

A collection with an `ownerProperty` takes no public uploads. The setting does nothing there: the depot stays closed to visitors like any other, and their uploads are refused. Use a collection without an owner.

### 2. Create the object first

A visitor can only add files to an object that already exists. Create it in the admin, or with an API call or automation, before the page goes live. A request for an object that does not exist is answered with a 404, so a visitor cannot create objects.

### 3. Add the form to your page

```twig
{{ cms.form.depotDrop('inbox', {collection: 'dropbox'}) }}
```

The first argument is the ID of the object, here `inbox`. The `collection` form setting names its collection. The form uses the property named `depot` unless you pass another one with `property`:

```twig
{{ cms.form.depotDrop('inbox', {collection: 'dropbox', property: 'files'}) }}
```

The form reads the types, the size limit and the comment setting from your `publicUpload` setting, so the visitor sees the right limits before they send anything.

## What a visitor sees

- A drop area that accepts the file types you listed.
- With `comments` on, a comment box and a **Send Files** button. The files wait until the visitor presses the button, and one comment is saved on every file in that batch.
- With a [tag menu](#let-visitors-pick-a-tag), the same **Send Files** button.
- With neither, files upload as soon as they are dropped.
- A message when a file is too big or is the wrong type.
- A remove button on any file that is waiting to be sent or was refused.

Comments are plain text, up to 1000 characters. Anything longer is cut off.

To hide the comment box on one page, add `comments: false` to the options of `cms.form.depotDrop`.

## Let visitors pick a tag

Add a `tags` list to the options. The visitor picks one from a menu, and it is saved on every file in that batch:

```twig
{{ cms.form.depotDrop('inbox', {collection: 'dropbox', tags: ['Invoice', 'Contract', 'Photo']}) }}
```

Each option is a string, or a `value` and `label` pair when the menu text should differ from the saved tag. The menu starts blank, and a visitor who leaves it blank sends untagged files.

Use `tagLabel` and `commentLabel` to change the labels. A label you set is shown exactly as written, so a site in several languages passes its own translated text:

```twig
{{ cms.form.depotDrop('inbox', {
	collection: 'dropbox',
	tags: ['Invoice', 'Contract', 'Photo'],
	tagLabel: 'Document type',
	commentLabel: 'Notes for our team'
}) }}
```

The options live in your template, so the server does not check the choice against them. It accepts one tag of plain text, up to 60 characters.

## Files that are never accepted

Some types are refused even when you list them in `extensions`. This covers every executable and script type, web pages (`html`, `htm`, `xhtml`, `shtml`), `svg` and `xml`, and the PHP variants such as `phar` and `pht`. A file is also refused when its contents turn out to be a web page, an SVG image, or PHP, whatever its name says.

Every dot-separated part of a filename is checked, not only the last one. A file named `acme.com.pdf` is refused because of the `com` part. Ask visitors to rename such files, or tell them in the page copy.

A name longer than 238 bytes is refused with a message that the name is too long. The stored name needs the other 17 bytes for its prefix. A name is measured as it will be stored, and that can be longer than what the visitor sent: `中` is stored as `zhong`.

## Where the files go

Each stored file gets a random prefix on disk, so nobody can guess its URL. The admin depot list shows the name the visitor sent, not the prefixed name.

Files are stored in the `folder` you set, or the depot root when you set none.

## Limits and abuse protection

Visitors need no CAPTCHA. The form carries a hidden check that real browsers pass without any action.

- A file dropped within 3 seconds of the page loading counts as a failed check, because a person cannot get there that fast.
- When the check fails, the form still answers that the upload worked, but nothing is stored.
- A form that has been open for a long time shows a message asking the visitor to reload the page.
- Large files are sent in 5 MiB pieces. A piece larger than 5 MiB in a multi-piece upload is refused.

Each IP address is also limited. The defaults:

| Limit name | Default | What it counts |
|------------|---------|----------------|
| `public-upload` | 20 per hour | Finished files |
| `public-upload-starts` | 40 per hour | Multi-piece uploads that were started |
| `public-upload-requests` | 300 per hour | Every request, including each piece |

Past a limit, the visitor gets a "too many uploads" message, and the response has a `Retry-After` header giving the seconds left in that limit's window. Change a limit, or switch it off with `'max' => 0`, in `config/tcms.php`:

```php
return [
	'rateLimits' => [
		'public-upload'          => ['max' => 50, 'window' => 3600],
		'public-upload-starts'   => ['max' => 80, 'window' => 3600],
		'public-upload-requests' => ['max' => 600, 'window' => 3600],
	],
];
```

See [Rate Limits](docs/operations/security#rate-limits) for how windows work.

## Who is limited

- **Visitors with no account** get the limits on this page.
- **A signed-in user with `update` rights** keeps the normal upload. The grant does not limit them.
- **A signed-in member without `update` rights** gets the same limits as a visitor, with these differences: they skip the bot check, and the form treats them as signed in, so it shows no comment box and no file type or size hints. The server still applies every limit.

To show the comment box to signed-in users, add `comments: true` to the options:

```twig
{{ cms.form.depotDrop('inbox', {collection: 'dropbox', comments: true}) }}
```

A member without `update` rights only has their comment saved when the `publicUpload` setting has `comments` on. A user with `update` rights always has it saved.

If you turn authentication off for the whole site, the limits still apply to every caller. Uploads that carry no bot-check token are dropped.

## Read this before you go live

- **Uploads are not virus scanned.** Treat every file as untrusted. Do not open files from strangers on a machine you care about, and keep the `extensions` list as short as you can.
- **Do not put the form inside `{% cache %}`.** The bot check is signed when the page is built and lasts 24 hours. A copy cached for visitors works for those 24 hours. After that, every upload shows an error asking the visitor to reload, and reloading returns the same stale page, so nobody can send files until the cache clears. A copy cached while someone was signed in has no bot check at all: every visitor's upload is dropped, and the form says it worked. This also applies to a page cached for all users by a server or CDN. Cache the rest of the page and leave the form out.
- **The object must exist first.** See step 2 above.
- **Reload the admin page before you save an object that takes uploads.** The Save button writes the depot's file list as it stood when the page loaded, so a file a visitor sent after that drops off the list. The file stays on disk, but the depot no longer shows it, and `tcms repair:files` only rebuilds a depot with no files listed. Deleting, moving and editing a file save on their own and are not affected.
- **Forced values are written again on each upload.** Every upload saves the object, so a [`protected: {"value": X}`](docs/schemas/validation#protected) field goes back to `X`, even if an admin changed it. If you use one to mark an object for review, each upload sends it back to review.
- **Protect the downloads.** Stored files are not listed to visitors, but anyone who knows a file's URL can download it unless the collection requires login. Set the collection's `groups` so only the right people can download. See [File & Depot](docs/fields/file-depot) and [Collection Settings](docs/collections/settings).
- **Escape what you print.** Twig autoescape is off in Total CMS. A comment is anonymous text from a stranger, and the original file name is too. If a template prints `{{ file.comments }}` or `{{ file.download }}` on a page, write `{{ file.comments|e }}` and `{{ file.download|e }}`. See [Template Output Escaping](docs/operations/security#template-output-escaping-twig).

## Getting notified

Each stored file saves the object, which fires the `object.updated` event once for that file, with its comment and download name already in place. A file sent in pieces fires it once, when the last piece lands. An [event Automation](docs/automations/triggers) can email you when files arrive.

1. Create a [Mailer](docs/notifications/mailer) template with the ID `new-upload`. Send it to your own address, fixed in the template, and use `{{ data.id }}` in the body.
2. Create an automation with an `event` trigger: event `object.updated`, collection `dropbox`.
3. Use this handler:

```php
<?php

return function ($ctx) {
    $ctx->mailer->sendEmail('new-upload', [
        'collection' => $ctx->event['collection'],
        'id'         => $ctx->event['id'],
    ]);

    return ['notified' => $ctx->event['id']];
};
```

Events are processed on the next `automations:process` tick, so the email can arrive a short time after the upload. `object.updated` also fires when you edit the object yourself, so the email goes out for those saves as well.
