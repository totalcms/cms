---
title: "Total CMS Badges"
description: "Add a Built with Total CMS badge to a site's footer with cms.badge: generic pill, stacked and text badges, and profile badges that link to a designer's profile on totalcms.co."
---

# cms.badge

The `cms.badge` namespace writes a "Built with Total CMS" badge: a small linked image, usually for a site's footer. The images are served by totalcms.co, so a badge stays current without your site changing. A designer's badge shows their latest photo and approved work as soon as totalcms.co has it.

There are five badges. Three are generic and need no account. Two belong to a designer and link to their profile on totalcms.co, so they need a free profile there.

| Function | Badge | Themes |
|---|---|---|
| `cms.badge.pill()` | "Built with Total CMS" on one line | `light`, `dark`, `auto` |
| `cms.badge.stacked()` | "BUILT WITH" over "Total CMS" | `light`, `dark`, `auto` |
| `cms.badge.text()` | "Powered by Total CMS" in your own text color | `color`, `mono` |
| `cms.badge.profile(handle)` | Your photo, "Total CMS designer" and @handle | `light`, `dark`, `auto` |
| `cms.badge.earned(handle)` | "Total CMS designer" with your approved sites and extensions | `light`, `dark`, `auto` |

Preview every badge and theme at [totalcms.co/badges](https://totalcms.co/badges).

## Basic Usage

```twig
<footer>
	&copy; {{ 'now'|date('Y') }} Studio Jyoti
	{{ cms.badge.pill() }}
</footer>
```

Pick a theme with the `theme` option:

```twig
{{ cms.badge.stacked({theme: 'dark'}) }}
{{ cms.badge.text({theme: 'mono'}) }}
```

## Themes

- `light` (the default) is for light backgrounds, `dark` for dark ones.
- `auto` follows the visitor's color scheme: the light badge normally, the dark one when their system is in dark mode. Use it only when your footer changes with the color scheme too; a footer that is always dark wants `dark`.
- The text badge inherits the color of the text around it. `color` draws the flame in the brand gradient; `mono` draws it in the text color as well.

An unknown theme falls back to the default.

## Profile Badges

Pass your handle on totalcms.co, the part after `/@` in your profile's address:

```twig
{{ cms.badge.profile('joeworkman') }}
{{ cms.badge.earned('joeworkman', {theme: 'auto'}) }}
```

- **profile** shows your photo (or your initial), "Total CMS designer" and your handle. It is available while your profile is public.
- **earned** shows how many of your sites and extensions are approved in the showcase, naming only the kinds you have: "3 sites", "1 extension", or "3 sites · 2 extensions". It is available once you have at least one approved item.

Both link to your profile, marked as yours (see below). If your profile is hidden later, the link goes to the totalcms.co home page and the image falls back to the pill, so a badge on a client's site never breaks.

### The `me` option

A profile badge goes on your own studio or portfolio site, so its link gets `rel="me"`, which says this site and your totalcms.co profile belong to the same person. Your profile already links back to your website, so the two now point at each other, and search engines and services such as Mastodon can confirm the pair.

Add your profile to your structured data as well: in **Site SEO**, add `https://totalcms.co/@your-handle` to **Social Profiles**, which becomes the Organization's `sameAs`, so your structured data lists the profile among your other accounts.

On a site that is not yours, such as a client's, pass `me: false`. The badge still links to your profile, but the site makes no claim to be you:

```twig
{{ cms.badge.earned('joeworkman', {me: false}) }}
```

Leave the Social Profiles entry off a client's site too.

A handle that is not a valid totalcms.co handle gives the pill instead.

The profile badges get a height but no width: their width follows your handle and your counts, and the browser takes it from the image.

## Without Twig

On a page Twig does not render, copy the HTML for any badge from [totalcms.co/badges](https://totalcms.co/badges). It is the same markup, so it works the same way. The Twig version keeps up with any change to the badges, such as a new theme, without you copying the code again.
