---
title: "WordPress Publishing (XML-RPC)"
description: "Publish to Total CMS from desktop and iOS writing apps that speak the WordPress XML-RPC API — MarsEdit, Byword, Ulysses, Open Live Writer."
related:
  - apis/api-keys
  - apis/rest-api
updated: 2026-07-28
---

# WordPress Publishing (XML-RPC)

Total CMS answers the same XML-RPC calls WordPress does, so desktop and iOS writing apps that already know how to talk to WordPress can publish straight into a `blog`-schema collection without knowing anything about T3's own API.

**Requires the Pro edition or higher.** Trials count as Pro for testing. The endpoint is off by default — see [Setup](#setup) below.

## Which apps work

Tested against the classic WordPress XML-RPC dialect (MetaWeblog, Blogger, and the `wp.*` extensions):

- **MarsEdit** (macOS)
- **Byword** (macOS/iOS)
- **Ulysses** (macOS/iOS)
- **Open Live Writer** (Windows)

**iA Writer is *not* supported.** iA Writer's WordPress publishing talks to the WordPress **REST API** (`/wp-json/wp/v2/...`), not XML-RPC — a completely different protocol that Total CMS does not implement. Do not enable this feature expecting iA Writer to connect; it will not.

## Setup

1. In the admin, go to **Settings → WordPress Publishing** and turn on **Enable XML-RPC Publishing**.
2. Go to **Utilities → API Keys** and create a new key. Under **Select Endpoints**, check **both**:
   - **XML-RPC Publishing (`/xmlrpc.php`)** — required to authenticate at all
   - **each collection** you want to publish into (e.g. `/collections/blog`)

   A key scoped to only the XML-RPC endpoint authenticates fine but then reports **zero blogs** — it has nowhere to publish. This is the single likeliest support question this feature will generate, so if a client connects but shows no blog in its site list, this is almost certainly why. Go back and add the collection.
3. Grant HTTP methods on the key to match what the app needs to do: `GET` to read, `POST` to create, `PUT` to edit, `DELETE` to remove. A read-only key can list and open posts but not publish.
4. In your writing app, add a new WordPress/MetaWeblog site using:
   - **Endpoint URL** — see below
   - **Username** — any Total CMS user's login. This is **not verified as a password** — it is used only to attribute authorship on posts you create. Whatever you type here just needs to resolve to a real user for the author name to show up correctly; a value that doesn't resolve to anyone still works, falling back to the key's name.
   - **Password** — the API key itself

Many clients (MarsEdit included) can also discover the endpoint automatically from just your site's home page URL, via the RSD document served at `{endpoint}?rsd`. This only works if the page the client fetches renders `{{ cms.assetsHead() }}` — that call is what emits the `<link rel="EditURI">` tag clients look for, and it only appears when XML-RPC Publishing is enabled. A template that doesn't render `cms.assetsHead()` won't advertise the endpoint, and auto-detect will fail even though the endpoint itself works fine. If auto-detect doesn't find it, just paste the **Endpoint URL** directly — that always works.

## Endpoint URLs

Two shapes are served, both under the site root — not under `/api`:

| Route | Collection selection |
|---|---|
| `POST {site}/xmlrpc.php` | The client's `blogid` selects the collection (a string, e.g. `blog`). `blogger.getUsersBlogs` / `wp.getUsersBlogs` lists every collection the key can reach |
| `POST {site}/xmlrpc/{collection}` | The collection is pinned by the URL; `blogid` is ignored entirely |

Use the collection-pinned form (`/xmlrpc/{collection}`) if your host's firewall or security scanner blocks the literal path `xmlrpc.php` — a common default rule, since a bare `xmlrpc.php` is one of the most probed paths on the web. It's also useful for clients that hardcode `blogid=1`.

On a subfolder install, both shapes hang off the same subfolder your site already lives in — e.g. `https://example.com/mysite/xmlrpc.php` — there is nothing extra to configure.

**With more than one blog collection granted:** several WordPress methods (`metaWeblog.getPost`, `metaWeblog.editPost`, `mt.getPostCategories`, `mt.setPostCategories`, `blogger.deletePost`) carry a post id but no `blogid` at all — that's the WordPress protocol, not a T3 limitation. These operations locate the post by looking for it in every collection the key can see. If exactly one contains it, the call proceeds normally. If the same id happens to exist in two or more granted collections, the call faults rather than guessing which one you meant — use the `/xmlrpc/{collection}` endpoint form to target the collection directly and remove the ambiguity.

A bare `GET` to either endpoint returns the plain-text string `XML-RPC server accepts POST requests only.` (used by some clients to verify the URL); `GET {site}/xmlrpc.php?rsd` returns the RSD discovery document.

## What round-trips and what doesn't

- **Images are not supported over this endpoint in v1.** Attempting to attach media (`metaWeblog.newMediaObject`, `wp.uploadFile`) returns a clear fault explaining that images must be added in the **Total CMS admin**, where cropping and alt text are also available. Because MarsEdit uploads media as part of publishing a post, **a post containing an image cannot be published from the app at all** — the failure surfaces as a publish error, not a silently dropped image. Text-only posts are unaffected. `wp.getOptions` reports `post_thumbnail: false`, which tells clients that support the hint not to offer featured-image UI in the first place.
- **A hero image set in the admin survives an edit made from a writing app.** Writes are patched onto the existing object, never replaced wholesale, and the WordPress post struct has no field for `image`/`gallery`/custom fields — so a text-only edit sent from MarsEdit cannot touch them.
- **Posts written in T3's own Tiptap editor may lose T3-specific markup once edited by a writing app.** Content round-trips as plain HTML; standard tags and attributes survive, but Tiptap extensions like `RawHTML` blocks and `InlineClass` spans can come back simplified or stripped after passing through a third-party editor. This is inherent to any round-trip through a different HTML editor, not a T3-specific bug.
- **Renaming a post (changing its ID/slug) is an admin-only operation.** `wp_slug` is honored on *create* only — in T3 the object ID is also its storage location and drives its public URL, so accepting a rename over this endpoint would mean silently deleting and recreating the post, breaking uploaded files and inbound links. Editing a post's title from a writing app does not rename it.
- **`private` posts and scheduled (`future`) posts both arrive in T3 as drafts.** T3 has no private-post concept, and core has no autopublish scheduler, so both statuses map to `draft: true` — failing hidden rather than accidentally public.

## Authorization: access groups are not enforced

The `username` parameter is display attribution only — it is never checked against a password, and access groups are not consulted at all for XML-RPC calls. **The API key's scopes (paths + HTTP methods) are the entire authorization story.** A key scoped to `/xmlrpc.php` + `/collections/blog` with `GET, POST, PUT, DELETE` can fully manage the `blog` collection regardless of which username was typed into the writing app, and regardless of what access group that username's user account belongs to elsewhere in the admin.

## Field mapping

| WordPress field | Total CMS `blog` field | Notes |
|---|---|---|
| `title` | `title` | |
| `description` | `content` | HTML; T3's own upload URLs are expanded to absolute on read, and collapsed back to path-relative on write |
| `mt_excerpt` | `summary` | |
| `mt_text_more` | `extra` | |
| `mt_keywords` | `tags` | Comma-separated string on the wire ↔ list array in storage |
| `categories` | `categories` | Array of names — no numeric term-ID registry |
| `date_created_gmt` / `dateCreated` | `date` | The GMT variant is preferred and converted to the site timezone; `dateCreated` is treated as already site-local |
| `sticky` | `featured` | Only written when the client actually sends it |
| `wp_slug` | `id` | **Create only** — ignored on edit, see above |
| `post_status` / the `publish` flag | `draft` | `publish` → `draft: false`; `draft`/`pending`/`private` → `draft: true`; `future` → `draft: true` with the given `date` (no autopublish) |
| `wp_author_display_name` | `author` | Read side; on write, **create only** — `metaWeblog.newPost` attributes the resolved username, but editing a post never changes its author |
| `postid` | `id` | Carried as a string — T3 slugs pass through unchanged |
| `permaLink` / `link` | — | Read-only, built from the collection's URL settings |
| `wp_password`, `mt_allow_comments`, `mt_allow_pings`, `custom_fields`, `wp_post_thumbnail` | — | Accepted on the wire and silently ignored |

A field a client never sends is never touched — `metaWeblog.editPost` patches only the keys present in the struct, so an app that edits only the title leaves every other field, including the hero image, exactly as it was.

## Troubleshooting

### Fault codes

| Code | Meaning |
|---|---|
| `403` | Bad credentials — the password (API key) didn't validate for the XML-RPC path |
| `401` | The key authenticated, but is not permitted to do this: either the HTTP method isn't in its scope (e.g. a GET-only key tried to publish), or the call hit a deliberately unsupported method (media upload, pages) |
| `404` | Unknown post or blog — including a key that authenticates but has no `/collections/{id}` grant matching the target collection |
| `429` | Rate limit exceeded for this IP (see the **Rate Limit** setting alongside the enable toggle) |
| `-32601` | Unknown method — the call isn't one T3 registers at all |
| `-32700` | Malformed request body |

### "My client connects but lists no blogs"

The API key authenticated (it has the `/xmlrpc.php` grant) but was not also scoped to any `/collections/{id}` path — or was scoped to a blog-schema collection the key holder can't otherwise see. Edit the key and add the specific collection path(s) you want to publish into.

### "The connection times out or is refused before I even see an error"

Some hosts run a web application firewall or a security-scanner rule that blocks `xmlrpc.php` outright, since it's one of the most commonly abused WordPress paths on the internet — the request never reaches PHP at all, so nothing in Total CMS's logs will show it. Try the collection-pinned form instead: `{site}/xmlrpc/{collection}`.

### "Attaching an image fails the whole post"

Expected in v1 — see [What round-trips and what doesn't](#what-round-trips-and-what-doesnt) above. Publish the text, then add the image in the Total CMS admin.
