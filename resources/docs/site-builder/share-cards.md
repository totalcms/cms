---
title: "Share Cards"
description: "Every page's social share card on one admin page, the way a link preview shows it, with the reason any card fell back to the plain image."
since: "3.6.10"
related:
  - site-builder/seo
  - twig/imageworks
---

# Share Cards

**Utilities → Reporting → Share Cards** shows the card every page shares on social media, the way a link preview shows it: the image, the title and the description. Check it before a launch. Most networks cache a share image for days, so a broken card that goes out with the first share stays broken.

Each card says why it looks the way it does. A card that fell back to the plain image says what stopped the [overlay](docs/site-builder/seo#share-image-overlays), and a filter shows only the cards that need a look.

## What It Shows

The page has one section for the Site Builder pages, then one for each collection the Site Builder serves.

- **Pages:** each page with a fixed route, such as `/about`. A page with a dynamic route (`/posts/{id}`) can't be listed, and a redirect has no card, so both are left out.
- **Collections:** each collection with a URL, pretty URLs on, and a `pages/{collection}.twig` template. Every object is shown.
- **Drafts are included,** with a **Draft** badge, because before a launch they are what is about to go live. A record with **No Index** on gets a badge too. Neither counts as a problem.
- **A page that isn't HTML,** such as a feed or `robots.txt`, has no card, so it is left out. A section's count drops to match once its cards load.

A collection with a URL but no Site Builder template, such as one a Stacks page serves, is named at the bottom of the page as skipped. Stacks pages aren't covered: Total CMS doesn't render them, so it can't preview them. The page needs the Site Builder, and says so on a site without it.

The cards load a section at a time, 25 at a time. The counts in the filter bar grow while they load. Loading the images also draws and caches them, so the first real share is served from the cache.

## Reading a Card

Each section header links to the overlay its cards draw: the collection's SEO settings, or **Site SEO** for the Pages section. Each card links to the page itself and to the record's edit page.

| Message | What it means | What to do |
|---|---|---|
| (none) | The overlay was drawn. | Nothing. |
| Shared as is | **Use the Image as Is** is on in the record's SEO card. | Turn it off to draw the overlay. |
| No overlay set | Neither the collection nor the site has an overlay. | Set one in **Site SEO → Social Image Overlay**. |
| Built by the page | The template passed `cms.seo.head()` its own values, not a record, so there is no record to draw from. | Nothing, if it's on purpose. This one doesn't count as a problem. |
| Doesn't come from this record's image | The share image isn't the record's image, its mapped image property, or the site default. | Share one of those images, or turn on **Use the Image as Is**. |
| No share image | The page shares no image at all. | Give the record an image, or set the **Default Image** in Site SEO. |
| ImageWorks refused layer N | A layer has a value ImageWorks won't take; the message names the parameter. | Fix that layer in the overlay. The first layer is layer 1. |
| Every layer was empty | Every layer's placeholders came back empty for this record. | Fill in the fields the overlay reads, or add a layer without placeholders. |
| The site key isn't set up | The install has no site key yet, so it can't sign a card's address. | Finish the setup wizard. |
| The page changed a value the card draws | The template changed a value the overlay draws before calling `cms.seo.head()`, so the card no longer matches the record. | See [below](#a-card-that-no-longer-matches). |
| No title / No description | The page shares no `og:title` or no `og:description`. | Fill in the record's title or description, or the SEO card's. |

## A Card That No Longer Matches

The share image's address carries a hash of everything the overlay draws. When a network fetches it, Total CMS reads the record again and draws the card only if the hash matches. A template that changes the record first breaks that:

```twig
{# The head signs "Total CMS: 3.6"; the image route reads "3.6" from the record. #}
{% set object = object|merge({title: 'Total CMS: ' ~ object.title}) %}
{{ cms.seo.head(object, {collection: 'releases'}) }}
```

The image route then shares the plain image. Leave the record as it is and change the share title another way: the SEO card's **Social Title**, or the collection's **Social Title Template**. Total CMS also writes one line a day to the ImageWorks log for each address that doesn't match.

## How the Pages Are Rendered

Each page renders through its real template, as you: your session, your login and your access groups. The page's own middleware (passcodes, access gates) doesn't run, because you're previewing, not visiting. A template that writes to the session or sets a cookie does so while it renders here.

A page that doesn't render gets the card its record works out to, labeled with why: it refused to render (`cms.notFound()`), it redirects, it answers with its own response, or it failed. Only a failure counts as a problem; the others are the template's own choice. A failed page shows the first line of its error; the Log Analyzer (**Utilities → System**) has the rest. One page that fails costs one card, and a chunk that can't load at all costs those 25 cards, not the page.

## On a Local or Staging Copy

The page links use the **Base URL** in Site SEO, because that's what a share shows. An image on the site's own address loads from the install you're on, so it shows what this copy draws; an image on another host, such as a CDN, loads from there.

## Access

Share Cards is a utility like the others. An access group can give it with **Share Cards** under its utilities.
