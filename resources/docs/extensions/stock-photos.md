---
title: "Stock Photos (Bundled Extension)"
description: "Search free Pixabay photos in the object editor and drag them onto image and gallery fields. Each photo is downloaded into the object with the photographer's credit."
since: "3.7.0"
related:
  - fields/image-gallery
  - extensions/bundled
---

# Stock Photos

Search [Pixabay](https://pixabay.com) from the object editor and put a photo on an image or gallery field without leaving the admin. The photo is downloaded and stored in the object like any upload. Its alt text and the photographer's credit are filled in for you.

The extension ships with Total CMS and is free in every edition. It is off until you enable it and enter an API key.

## Setup

1. Make a free account at [pixabay.com](https://pixabay.com/accounts/register/).
2. Copy your API key from [pixabay.com/api/docs](https://pixabay.com/api/docs/) (it is shown there once you are logged in).
3. In **Admin → Extensions**, enable **Stock Photos**, open its settings, and paste the key.

| Setting | What it does |
|---|---|
| Pixabay API Key | Your key. It stays on the server; the browser never sees it. |
| Safe Search | Only show photos suitable for all ages. On by default. |
| Default Orientation | The shape filter the search starts with: any, landscape or portrait. |

## Use it

Each image and gallery field gets a small stock-photo button at the right of its label, and the object page's ⋯ actions menu gets a **Stock Photos** item. Either opens the panel:

1. Search for a word, such as `pasta` or `restaurant interior`.
2. Press **+** under a photo to put it in the field shown in **Add to**, or **drag** a photo onto any image or gallery field on the page.

Opening the panel from a field's button selects that field in **Add to**; opening it from the menu selects the first one. The **+** turns into a check once the photo is in the field. Change it there to fill another field without closing the panel. Fields inside cards and deck items are listed under their parent, such as *Menu › Photo (2)*.

Image fields inside cards and deck items work the same way. Press **Esc** or **×** to close the panel, or click the field's button again.

An image field takes one photo and replaces the one it has. A gallery adds the photo to the end. On a new object, the photo waits in the field and uploads when you save, like a file you dropped from your computer.

Every photo arrives with:

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

- Searches go through the extension's admin route, which adds your key and calls the Pixabay API. Results are cached for 24 hours, as Pixabay requires, so repeated searches don't count against your limit of 100 requests a minute.
- When you drop a photo, the field downloads it through the extension's download route, which only fetches from Pixabay's own servers. The field then uploads it like any file, so the field's upload rules (size, dimensions, count) still apply.
- Preview images in the panel come from Pixabay and expire after a day. Only the photo you add is stored.

Any editor who can open the object editor can use the panel. To hide it from a group, remove the extension from that group's **Extension Access** in Access Groups.

The photo drop the panel uses is a core feature, open to any photo source: see [Adding photos from another source](docs/fields/image-gallery#adding-photos-from-another-source).
