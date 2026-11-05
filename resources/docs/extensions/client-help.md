---
title: "Client Help (Bundled Extension)"
description: "Write how-tos and notes for your client and show them inside the admin, in a help area that matches the built-in docs."
since: "3.6.8"
related:
  - extensions/bundled
  - fields/styled-markdown
---

# Client Help

Write how-tos and notes for your client, and show them only inside their admin. The client gets a Help item in the admin menu, with featured articles on a home page and a sidebar organized by category. It uses the same layout as the built-in docs.

The extension ships with Total CMS and needs the Standard edition or higher.

## Turn it on

Go to **Extensions** and enable **Client Help**. It creates a collection called **Client Help** (id `client-help`) using the [`blogdown` schema](docs/fields/styled-markdown), so you write in Markdown.

The collection has no RSS feed, no sitemap entry, no URL and no public API access. These are defaults, so changing them in the collection's settings would expose the posts. Turning the extension off leaves your posts in place.

## Write a post

Add posts to the **Client Help** collection like any other collection.

- **Categories** build the sidebar. A post in two categories appears in both. A post with no category appears under **General**.
- **Featured** puts the post on the help home page as a card, with its image if it has one.
- **Draft** keeps a post out of the help area until it is ready, for admins as well.

The sidebar sorts categories by name and posts by title.

Every other field is optional, and a field you leave empty doesn't appear:

| Field | Where it shows |
|---|---|
| Author and Date | A line under the title |
| Image | Below that line, and on the home page card |
| Summary | Above the content, and on the home page card |
| Content | The article |
| Media | After the content. Video and audio URLs get a player, and any other URL becomes a link |
| Gallery | After the media, as thumbnails that open the full image |
| Extra Content | After the gallery. Its headings join **On this page** |
| Tags | Labels at the end of the article |
| Updated Date | **Last updated** at the bottom of the article |

Created Date isn't shown, because Date is the one readers see. The Date field fills in today's date by default.

Prefer styled text? Change the collection's schema to `blog`. The help pages check the field type and display either one. Existing posts are not converted.

## Hide the collection from clients

Help pages read posts on the server, so a client needs access to the extension but not to the collection. Hiding the collection is a step you take once:

1. Go to **Access Groups** and open the group your client is in.
2. Under collections, turn off access to all collections and allow only the ones your client should manage. Leave out **Client Help**.
3. Leave the extension allowed. Every shipped group allows all extensions.

Until you do this, the shipped groups can reach the collection directly: `editor` can edit posts, and `viewer` and `default` can read them.

Access groups have no way to exclude one collection, which is why the group needs a list of allowed collections. To hide help from a group, remove the extension from that group.

## Home page note

The **Home Page Note** setting appears at the top of the help home. It is Twig, so you can use anything in `cms`:

```twig
Stuck on something? Text me any time.

{{ cms.collection.objects('blog')|length }} posts on your site so far.
```

If the note has an error, the page still loads. Admins see the error in place of the note, and clients see nothing. Only admins can edit extension settings.

## Custom CSS and HTML

**Custom CSS** and **Custom HTML** are added to the help pages only. Custom HTML goes at the end of the page content.

The **Nav Label** setting renames the help area. It defaults to **Help**, and you can change it if that gets confused with the built-in docs.

## Images and privacy

Images and files in help posts are public by URL, like all Total CMS images. Don't put anything sensitive in a screenshot.

If your site uses Algolia search, saved posts are indexed there like other content.

## Reuse your posts on another site

Copy the folder, then rebuild the index:

1. Enable Client Help on the new site.
2. Copy `tcms-data/client-help/` from the old site to the same place on the new site.
3. Run `tcms repair:index client-help`.

Images come across with the folder. Settings don't, so copy the note and any custom CSS and HTML by hand. `tcms push` and `tcms pull` don't carry this collection.
