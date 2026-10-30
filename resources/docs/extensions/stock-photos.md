---
title: "Stock Photos (Bundled Extension)"
description: "Search free Pixabay photos, or your own library galleries, in the object editor and drag them onto image and gallery fields. Each photo is copied into the object with its alt text and credit."
since: "3.7.0"
related:
  - fields/image-gallery
  - extensions/bundled
---

# Stock Photos

Search [Pixabay](https://pixabay.com), or your site's own photo library, from the object editor and put a photo on an image or gallery field without leaving the admin. The photo is copied into the object like any upload, with its alt text and credit filled in.

The extension ships with Total CMS and is free in every edition. It is off until you enable it and give it something to search: a Pixabay API key, one or more library galleries, or both.

## Setup

In **Admin → Extensions**, enable **Stock Photos** and open its settings.

| Setting | What it does |
|---|---|
| Pixabay API Key | Your key. It stays on the server; the browser never sees it. Leave it empty to use only your library galleries. |
| Library Galleries | Galleries editors can pick photos from. Each is a tab in the panel, in the order listed. |
| Safe Search | Only show Pixabay photos suitable for all ages. On by default. |
| Default Orientation | The shape filter the search starts with: any, landscape or portrait. |

To search Pixabay, make a free account at [pixabay.com](https://pixabay.com/accounts/register/) and copy your API key from [pixabay.com/api/docs](https://pixabay.com/api/docs/) (it is shown there once you are logged in).

### A photo library of your own

Any gallery in the reserved `gallery` collection can be a library: the client's own photos, a set of brand images, the photos from a shoot. Create the gallery, upload the photos, and fill in their alt text, tags and credits once. Then pick it under **Library Galleries**. Pick several to give each its own tab, such as *Team*, *Dishes* and *Events*.

A photo taken from a library is **copied** into the field, with its alt text, credit, tags, link and focal point. After that the copy is independent: editing it changes nothing in the library, and deleting a photo from the library doesn't touch the pages that use it. The same photo used on three pages is stored three times.

Editors only see a library tab if their access groups let them read the `gallery` collection.

## Use it

Each image and gallery field gets a small stock-photo button at the right of its label, and the object page's ⋯ actions menu gets a **Stock Photos** item. Either opens the panel:

1. When there is more than one source, pick a tab. Your library galleries come first, in the order they are listed in the settings, then **Pixabay**. A library lists all of its photos straight away; Pixabay waits for a search.
2. Search for a word, such as `pasta` or `restaurant interior`. In a library, the search matches the photos' alt text, tags, file names and photographers. Switching tabs runs the same search in the new source.
3. Press **+** under a photo to put it in the field shown in **Add to**, or **drag** a photo onto any image or gallery field on the page.

Opening the panel from a field's button selects that field in **Add to**; opening it from the menu selects the first one. The **+** turns into a check once the photo is in the field. Change it there to fill another field without closing the panel. Fields inside cards and deck items are listed under their parent, such as *Menu › Photo (2)*.

Image fields inside cards and deck items work the same way. Press **Esc** or **×** to close the panel, or click the field's button again.

An image field takes one photo and replaces the one it has. A gallery adds the photo to the end. On a new object, the photo waits in the field and uploads when you save, like a file you dropped from your computer.

Every library photo arrives with the alt text, credit, tags, link and focal point it has in the gallery. Whether it is featured in the gallery doesn't carry over.

Every Pixabay photo arrives with:

- **Alt text** made from the photo's Pixabay tags ("Pasta, food, italian"). Rewrite it to describe the photo on your page; tags are not good alt text.
- **A credit** with the photographer, a link to their Pixabay profile, the photo's Pixabay page and the license. See [Photo Credit](docs/fields/image-gallery#photo-credit).

Photos are downloaded at Pixabay's large size, 1280 pixels on the longest side, which is the biggest an ordinary API key may download. That's plenty for content images and cards, but soft for a full-width hero on a high-resolution screen. Pixabay grants [full API access](https://pixabay.com/api/docs/#api_search_images) on request; with an approved key the extension downloads the original file instead, with nothing to configure. The site's maximum download size still applies. ImageWorks resizes from whatever was downloaded.

## Your responsibilities

Pixabay photos are free to use under the [Pixabay Content License](https://pixabay.com/service/license-summary/). You, not Total CMS, agree to it when you get your key, and you are responsible for following it. In short:

- **Don't sell unaltered copies** of a photo, on its own or as a print, poster or download.
- **Don't offer the files for download.** Don't put Pixabay photos in a Depot field or anywhere visitors can download the original file. Use them in your pages.
- **Check trademarks and people.** Pixabay doesn't promise model or property releases. Don't use a photo with a recognizable logo, brand or person in a way that suggests they endorse you.
- **Remove a photo if asked.** If a rights claim is made, remove the image from your site and tell Pixabay at info@pixabay.com. The credit's `sourceUrl` tells you exactly which photo it is.

Pixabay also asks for its name to appear where search results are shown; the panel does this, with a **License** link to the Pixabay Content License beside it. A photo credit on your public pages is welcome but not required.

## How it works

- The panel asks the extension's admin routes which sources the editor may use, and shows a tab for each.
- A library photo is copied from the gallery's original file, not through ImageWorks, so it is never re-encoded or watermarked on the way. Only galleries chosen in the settings, and only photos they list, can be read this way.
- Pixabay searches go through the extension's admin route, which adds your key and calls the Pixabay API. Results are cached for 24 hours, as Pixabay requires, so repeated searches don't count against your limit of 100 requests a minute.
- When you drop a photo, the field downloads it through the extension's download route, which only fetches from Pixabay's own servers. The field then uploads it like any file, so the field's upload rules (size, dimensions, count) still apply.
- Preview images in the panel come from Pixabay and expire after a day. Only the photo you add is stored.

Any editor who can open the object editor can use the panel. To hide it from a group, remove the extension from that group's **Extension Access** in Access Groups.

The photo drop the panel uses is a core feature, open to any photo source: see [Adding photos from another source](docs/fields/image-gallery#adding-photos-from-another-source).
