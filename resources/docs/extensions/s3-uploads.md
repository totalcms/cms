---
title: "S3 Uploads (Bundled Extension)"
description: "Store large files in your own S3-compatible bucket. Editors upload straight from the browser to Amazon S3, Cloudflare R2, Backblaze B2, Wasabi or MinIO, with counted download links, private buckets and automatic cleanup."
since: "3.7.0"
related:
  - extensions/podcast
  - extensions/bundled
  - twig/cache-tag
---

# S3 Uploads

Store large files in a bucket you own. An editor drops a file on a field and the browser sends it straight to the bucket. Total CMS keeps a small record that says where the file is. The file never passes through PHP, so upload size limits, script timeouts and your host's bandwidth stop being a problem.

It works with Amazon S3 and with any provider that speaks the S3 API: Cloudflare R2, Backblaze B2, Wasabi, MinIO and others.

The extension ships with Total CMS and needs the **Pro** edition. It adds:

- two field types: `s3file` for one file and `s3depot` for many
- a download link on your own domain that counts downloads
- support for private buckets, where every link is signed and expires
- cleanup: a replaced or deleted file is removed from the bucket

Use it for PDFs, documents, downloads, audio and video. Images that pages display belong in the core image and gallery fields, which resize them.

## Set it up

1. In **Admin → Extensions**, enable **S3 Uploads** and open its settings.
2. Fill in the bucket, the region and both keys. The table below explains each setting.
3. Open **Bucket Setup** from the extension's links and press **Test Connection**. It lists one file in the bucket, which proves the endpoint, region, bucket and keys in one request.
4. On the same page, copy the CORS rules and add them to your bucket. See [Bucket CORS](#bucket-cors).
5. Add an `s3file` or `s3depot` property to a schema. See [The fields](#the-fields).

| Setting | What it does |
|---|---|
| Endpoint | Leave blank for Amazon S3. For any other provider, its S3 address. See the table below. |
| Region | The bucket's region, such as `us-east-1`. Cloudflare R2 uses `auto`. |
| Bucket | The bucket name. |
| Addressing | Leave on Automatic. It puts the bucket in the hostname for Amazon S3 and in the path for other providers. |
| Access Key, Secret Key | A key that can reach this one bucket. The keys stay on the server; the browser never sees them. |
| Prefix | A folder inside the bucket for this site, such as `my-site/`. **Set one.** See [One prefix per install](#one-prefix-per-install). |
| Visibility | Public or Private. See [Public and private buckets](#public-and-private-buckets). |
| Signed Link Lifetime | How long a signed link works, in seconds. From 60 to 604800 (7 days). The default is 3600. |
| Public Base URL | A CDN or custom domain in front of the bucket, such as `https://files.example.com`. Public visibility only. |

### Providers

| Provider | Endpoint | Region |
|---|---|---|
| Amazon S3 | *(blank)* | The bucket's region, such as `us-west-2` |
| Cloudflare R2 | `https://<accountid>.r2.cloudflarestorage.com` | `auto` |
| Backblaze B2 | `https://s3.<region>.backblazeb2.com` | The region in the endpoint, such as `us-west-004` |
| Wasabi | `https://s3.<region>.wasabisys.com` | The region in the endpoint |
| MinIO | Your server's URL, such as `https://minio.example.com` | The server's region, usually `us-east-1` |

### The key

Make a key for this bucket alone. Never use a root or account-wide key.

On Amazon S3, attach a policy to the IAM user the key belongs to (IAM → Users → the user → Add permissions → Create inline policy → JSON). It is not a bucket policy: a bucket policy needs a `Principal`, and S3 refuses this one without it.

The **Bucket Setup** page builds the policy from your saved settings, with a button to copy it, so there is nothing to edit. It looks like this, with your bucket in place of `my-bucket` and your Prefix in place of `my-site/`:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::my-bucket/my-site/*"
    },
    {
      "Effect": "Allow",
      "Action": "s3:ListBucket",
      "Resource": "arn:aws:s3:::my-bucket",
      "Condition": { "StringLike": { "s3:prefix": "my-site/*" } }
    }
  ]
}
```

On Cloudflare R2, create an API token with **Object Read & Write** for the one bucket.

### Bucket CORS

The browser uploads to the bucket directly, so the bucket must allow your site. Without these rules every upload fails with "The upload was blocked before it reached the bucket."

The **Bucket Setup** page prints the rules with your site's address filled in, with a button to copy them. For Amazon S3, Wasabi and MinIO they look like this:

```json
[
  {
    "AllowedOrigins": ["https://www.example.com"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["content-type"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

- **Amazon S3:** bucket → Permissions → Cross-origin resource sharing (CORS).
- **Cloudflare R2:** bucket → Settings → CORS policy. It takes the same JSON.
- **Backblaze B2:** `b2 bucket update --cors-rules` with `allowedOperations` of `s3_put`, `s3_get` and `s3_head`, `allowedHeaders` of `content-type`, and `exposeHeaders` of `etag`.

List every address editors use the admin on. A staging site or a local site such as `https://site.test` each needs its own entry in `AllowedOrigins`.

CORS can only be tested from a browser, so the Test Connection button cannot check it. If an upload is blocked, the browser console shows "blocked by CORS policy".

### One prefix per install

Give production, staging and each local copy its own prefix, such as `my-site/`, `my-site-staging/` and `my-site-local/`.

Cleanup removes files under the prefix that this install does not use. Two installs that share a bucket and a prefix will delete each other's files.

Do not change the prefix once files exist. Files stored under the old prefix stop being served.

## The fields

Add a property to a schema and set its field to `s3file` or `s3depot`. The property type is `json`.

```json
"brochure": {
  "type": "json",
  "field": "s3file",
  "label": "Brochure",
  "settings": {
    "rules": {
      "size": { "max": 50000 },
      "filetype": ["application/pdf"],
      "filename": ["\\.pdf$"]
    }
  }
}
```

| Field | Stores | In the admin |
|---|---|---|
| `s3file` | One file | A drop zone, then a card with Open, Replace and Remove |
| `s3depot` | Many files, in the order you arrange them | A drop zone and a card per file. Drag a card to reorder. Three files upload at a time. |

On an existing object a file uploads as soon as you drop it, and it is saved when the upload finishes. On a new object the file waits until you save, because the object needs its ID first.

The fields work as top-level properties. They do not work inside a card or a deck.

### Field settings

| Setting | What it does |
|---|---|
| `rules.size` | `{ "min": 10, "max": 50000 }`, in **KB**, as for core upload fields. |
| `rules.filetype` | A list of file types. Each is a regular expression and any one may match: `"application/pdf"`, `"audio/.*"`. |
| `rules.filename` | A list of regular expressions for the file name: `"\\.pdf$"`. |
| `rules.count` | `{ "max": 20 }`. The most files an `s3depot` holds. |
| `folder` | Store this property's files in one flat folder under the prefix instead of a folder per object. Files in a folder are not deleted when replaced or removed; the nightly cleanup removes them instead. |
| `protectedByCollection` | Set to `false` to leave this property's files open to everyone in a collection that has access groups. The default is `true`, as for core file fields. See [Protected downloads](#protected-downloads). |

One upload can be up to 5 GB.

### What is stored

The property holds a record of the file, never a URL. Links are built when a page renders, so you can move to another bucket, provider or CDN without touching your content.

```json
"brochure": {
  "id": "9f2c1a7e",
  "key": "my-site/brochures/spring/brochure/9f2c1a7e-brochure-2026.pdf",
  "name": "Brochure 2026.pdf",
  "size": 48211334,
  "mime": "application/pdf",
  "etag": "3d0f6c",
  "uploadedAt": "2026-09-05T14:02:11+00:00",
  "uploadedBy": "joe"
}
```

An `s3depot` stores a list of these. Audio and video files also get a `duration` in seconds, measured in the browser when the file is uploaded.

## Twig

The extension adds an `s3` global. Pass it the object, and name the collection and the property:

```twig
<a href="{{ s3.url(post, {collection: 'blog', property: 'brochure'}) }}">
  {{ post.brochure.name|e }}
</a>
({{ s3.size(post, {collection: 'blog', property: 'brochure'}) }})
```

| Function | Returns |
|---|---|
| `s3.url(object, options)` | A link to the file. |
| `s3.download(object, options)` | A link that makes the browser save the file under its original name. |
| `s3.size(object, options)` | The size for people: `46 MB`. |
| `s3.downloads(object, options)` | How many times the current file was downloaded. |
| `s3.totalDownloads(object, options)` | How many times any file of the property was downloaded, ever. |
| `s3.files(object, options)` | Every file of the property, for an `s3depot`. |

Each returns an empty value when the property has no file, so a template needs no extra check.

The first argument is the object or its ID, as with `cms.media.download()`:

```twig
{{ s3.url('spring-brochure', {collection: 'blog', property: 'brochure'}) }}
```

Pass the object when the template already has it. An ID makes the site look the object up. An ID that matches no object shows an error while you develop, and prints nothing on a live site.

| Option | What it does |
|---|---|
| `collection`, `property` | Required. |
| `file` | The `id` of one file in an `s3depot`. |
| `direct` | `true` links to the bucket itself instead of the counted link. |
| `expires` | Seconds a signed link from `direct` lasts, on a private bucket. |

List the files of an `s3depot`:

```twig
{% for file in s3.files(doc, {collection: 'docs', property: 'files'}) %}
  <a href="{{ file.download }}">{{ file.name|e }}</a>
  {{ file.sizeLabel }}, {{ file.downloads }} downloads
{% endfor %}
```

Each item has `id`, `name`, `size`, `sizeLabel`, `mime`, `duration`, `url`, `download` and `downloads`.

File names come from the editor's computer. Print them with `|e`, as the examples do.

If templates must keep working while the extension is switched off, wrap them in `{% if s3 is defined %}`.

## Download links

`s3.url()` and `s3.download()` give links on your own site. They are named like core's own file links and behave the same way:

```
/api/ext/totalcms/s3-uploads/stream/blog/my-post/brochure
/api/ext/totalcms/s3-uploads/download/blog/my-post/brochure
```

| | `s3.url()`, the `/stream` link | `s3.download()`, the `/download` link |
|---|---|---|
| What the browser does | Shows the file when it can, as with a PDF, an image or audio | Saves the file under its original name |
| Counts the download | Yes | Yes |
| Fires `file.downloaded` | No | Yes |
| Use it for | Links, players, podcast feeds | A "Download" button |

Each link counts the download and then sends the visitor to the file. It has three advantages over a link to the bucket:

- **It never expires**, even when the bucket is private.
- **It stays the same** when you change bucket, provider or CDN.
- **It counts.** Show the count with `s3.downloads()`, or run `tcms s3:stats`.

A download is counted when a visitor fetches the file from its start. A media player that seeks within a file, or checks its first two bytes before playing, is not counted again.

Two counts are kept:

- **The file's count** belongs to the stored file. When you replace or remove the file, it starts again at zero. `s3.downloads()` reads it.
- **The all-time total** belongs to the property and never resets. It covers every file the property has held, so a brochure you replace each quarter keeps its history. `s3.totalDownloads()` reads it. For an `s3depot` it covers every file the depot has held, including removed ones.

The field shows both when they differ, as in "7 downloads · 40 all time", and `tcms s3:stats` lists both. Deleting the object deletes both.

A counted `/download` also fires the [`file.downloaded`](docs/extensions/events) event, the same event a core `/download` link fires. Automations and extensions that react to downloads see S3 files too, and a signed-in user's download appears in the [Activity](docs/operations/activity) log. The `/stream` link only counts, as core's does, so a podcast feed or a player does not fire the event for every play.

The site counts the visitor being sent to the file. The bucket serves the file itself, so the site cannot tell whether the transfer finished.

The `/download` link always sends the visitor to a signed link, because only a signed request can name the saved file. It works whether or not the bucket allows public reads.

### Public and private buckets

| Visibility | Where the link leads | Use it for |
|---|---|---|
| Public | The file's address at the bucket, or at your CDN when Public Base URL is set | Files anyone may have. The bucket or CDN must allow public reads. |
| Private | A signed link that works for the Signed Link Lifetime | Files that should not be reachable without going through your site. The bucket stays closed. |

If links answer 403 with Public visibility, the bucket does not allow public reads. Either allow them at the provider or switch to Private. For Amazon S3, the Bucket Setup page builds the bucket policy that allows them; Block Public Access must be off for the bucket.

With `direct: true` on a private bucket, `s3.url()` prints the signed link itself. A signed link expires, and nothing that caches the page knows that. If the link sits inside a [`{% cache %}`](docs/twig/cache-tag) block, give the block a shorter time than the Signed Link Lifetime. The same goes for a page cache or CDN in front of your site. The default `/stream` and `/download` links never expire, so they are safe to cache.

### Protected downloads

S3 files follow the same rule as core files. When the collection has access groups, its files are protected: the `/stream` and `/download` links let through only a logged-in member of one of those groups. A visitor who is not logged in goes to the login page. A member without access gets a 403. Super admins always get the file, and the `default` group means any logged-in member.

A collection without access groups protects nothing.

To leave one property open in a collection that has groups, set `protectedByCollection` to `false` in its settings. Do this for anything the public must reach, such as podcast audio:

```json
"audio": {
  "type": "json",
  "field": "s3file",
  "settings": { "protectedByCollection": false }
}
```

The setting covers every file of the property. Core's per-file switch and per-file password are not available for S3 files.

Each protected download through the `/download` link is written to the file-access log with the member who took it, as protected core files are.

Use a **private** bucket with protected files. On a public bucket the file's own address works for anyone who has it, so the protection only covers the link on your site.

## Cleanup

Files are removed from the bucket as content changes:

- **Replace or remove a file, then save:** the old file is deleted, and its download count with it.
- **Delete an object:** its files and download counts are deleted.
- **Clone an object:** the clone gets its own copy of each file.

Some files are left behind: an upload whose browser tab was closed before it finished, the files of a deleted collection, files in a `folder` property. A nightly automation removes these. It deletes a file only when no object uses it and it is more than a day old.

The automation deletes only on a live site. On a development or staging site it reports what it would delete. It does nothing when no prefix is set.

Run it by hand with the CLI:

```bash
tcms s3:prune                      # report what would be deleted
tcms s3:prune --delete             # delete it
tcms s3:prune --older-than=7d      # only files unused for a week
tcms s3:prune --max=200            # delete at most 200 in one run
tcms s3:stats                      # download counts and all-time totals, most downloaded first
tcms s3:stats blog --json
```

`s3:prune` refuses to run without a prefix. If the bucket holds nothing but this site's files, `--whole-bucket` lets it run anyway.

If any object cannot be read, the run deletes nothing and says which object was the problem. A file that an unreadable object uses would otherwise look unused.

### If cleanup does not run

Open **Admin → Extensions → S3 Uploads** and check that **Event Listeners**, **Automations** and **CLI Commands** are switched on. After an update that adds a capability to an extension you already enabled, Total CMS leaves the new capability off until you turn it on.

## Hosting a podcast

Podcast audio is the case this extension was built for: large files, served from a bucket with no load on your site.

1. Make a custom schema from `podcast-episode` and change its `audio` property to `"field": "s3file"` with `"type": "json"`.
2. Create the episodes collection from that schema.
3. Set Visibility to **Public**. Podcast apps keep an episode's address for years, and a signed link would stop working.

The [Podcast](docs/extensions/podcast) feed then uses the counted `/stream` link for each episode, with the file's type and size. When an episode's Duration is empty, the feed uses the length measured at upload.

For download statistics that podcast directories accept, put a prefix service such as OP3 in front of the feed's audio links. The count shown by `s3.downloads()` is a simple total.

## Limits

- One upload can be up to 5 GB.
- Uploads work in the admin, not on public forms.
- The fields work as top-level properties, not inside cards or decks.
- Restoring an older version of an object can bring back a record of a file that has since been deleted from the bucket.
- An agent connected through MCP can read the stored records, but cannot upload to these fields.

## Errors

| Message | What to do |
|---|---|
| The upload was blocked before it reached the bucket. | Add the [CORS rules](#bucket-cors) to the bucket, for the address the admin is open on. |
| The bucket refused the upload (403). | The upload link expired, or the key cannot write to the bucket. Try again, then check the key's permissions. |
| The bucket refused the request (SignatureDoesNotMatch). | Check the secret key. |
| The bucket refused the request (InvalidAccessKeyId). | Check the access key. |
| The bucket refused the request (AccessDenied). | The key lacks a permission. See [The key](#the-key). |
| The bucket is in *region*; set Region to that. | Change the Region setting. |
| S3 Uploads is not set up yet. | Add the bucket, region and keys in the settings. |
| S3 Uploads needs Total CMS Pro. | Uploads need the Pro edition. Links to files already stored keep working. |
