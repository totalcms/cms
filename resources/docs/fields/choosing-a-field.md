---
title: "Choosing a Field"
description: "Which Total CMS field type to use for a given kind of value, why text is the last resort, the five things 'category' can mean, and where the reference schema that demonstrates every field lives."
related:
  - fields/all-fields
  - schemas/reference
  - fields/property-options
  - mcp/agent-editing
audience: beginner
updated: 2026-09-09
---

# Choosing a Field

A schema property has two halves: `field` decides how the admin edits the
value, and `type` decides how it is stored. Pick `field` first. When a
property omits `type`, Total CMS fills it in from the field on save, so a
well-chosen field gives you validation, the right editor, the right storage
shape, and the right Twig filters without further work.

The rule that matters most: **match the field to the shape of the value,
and use `text` only when nothing more specific fits.** A date stored in a
text field cannot be filtered by "this month". A number stored in a text
field sorts as a string. A yes/no stored as text is a typo waiting to
happen. Every one of those has a field built for it.

## The decision table

| The value is… | Use | Notes |
|---|---|---|
| the object's identity, part of its URL | `id` | Every schema needs one. Usually `autogen` from the title. |
| a name, headline or short label | `text` | Add `pattern`, `minLength`, `maxLength` for codes with a known format. |
| a summary, excerpt or plain note | `textarea` | No formatting. |
| an article body, formatted content | `styledtext` | Rich text, stored as HTML. |
| an embed snippet, custom CSS, raw markup | `code` | Set `mode` to `html`, `css` or `javascript`. |
| an email address | `email` | Validated. Never `text`. |
| a phone number | `phone` | Formatted on output with `formatPhone`. |
| a link to another site | `url` | Validated absolute URL. |
| a logo or icon drawn as vectors | `svg` | Sanitized on save. |
| a time of day with no date | `time` | 24-hour `HH:MM`. |
| an API key or token | `secret` | Masked, and hidden from MCP unless exposed. |
| a login credential | `password` | Hashed. User schemas only. |
| structured data with an unknown shape | `json` | Prefer a `card` once the shape is known. |
| a value set by code, never edited | `hidden` | Stored, never rendered. |
| yes or no | `toggle` | Featured, draft, in stock. `checkbox` is the consent-style variant for forms. |
| one choice from a fixed list | `select` | Status, size, layout. `radio` shows two to five choices all at once. |
| several choices from a fixed list | `checklist` | `multiselect` for a long list. |
| tags or categories editors grow over time | `list` with `propertyOptions` | See [categories](#which-category-do-you-mean) below. |
| a quantity, count, rating or sort order | `number` | Never `text`. |
| money | `price` | Locale-aware. |
| a bounded value, a percentage | `range` | `min`, `max`, `step`. |
| a calendar date | `date` | Publish dates, event dates, deadlines. Enables date filters and sorting. |
| a date and time, a timestamp | `datetime` | `onUpdate: true` for a last-modified stamp. |
| a colour | `color` | Stored as hex and OKLCH. |
| one image | `image` | Hero, thumbnail, photo. Resized through ImageWorks. |
| several images | `gallery` | |
| one downloadable file | `file` | |
| a library of files and folders | `depot` | |
| a hosted video | `video` | YouTube, Vimeo and others, by URL. |
| a fixed group of related fields | `card` | Address, hero, author block. Shape comes from another schema. |
| a repeating list of structured items | `deck` | Features, FAQs, team members. `deckTable` edits the same data as a grid. |
| text translated per locale | `localizedtext`, `localizedtextarea`, `localizedstyledtext` | Pro edition. |
| search-engine metadata for a public page | `card` referencing `seo` | See [SEO](docs/site-builder/seo#adding-the-card-to-your-own-schema). Index it. |

A computed value is not a field type. Put `calc` in the settings of a
`number` or `price` field and it becomes read-only and derived. See
[All Field Settings](docs/fields/all-fields#calc-computed-fields).

## Which "category" do you mean?

The word is used for five different things. Getting this wrong is the most
common schema mistake after over-using `text`.

| You mean… | It is | Where |
|---|---|---|
| Blog-style categories or tags an editor adds freely, several per object | a `list` field with `propertyOptions: true` | on the object schema |
| A dropdown with fixed choices, one per object | a `select` field with static `options` | on the object schema |
| Several fixed choices per object | a `checklist` field with static `options` | on the object schema |
| Grouping collections in the admin sidebar | the collection's `category` setting | Collections → Settings |
| Grouping schemas in the schema editor sidebar | the schema's `category` | top level of the schema JSON |

`propertyOptions: true` offers every value already used in the collection
as a suggestion while still allowing new ones, so the list of categories
grows with the content and never needs a schema edit. That is how the
built-in `blog` schema's `categories` and `tags` work. Static `options` is
the opposite: the choices are fixed until someone changes the schema. See
[Property Options](docs/fields/property-options).

`Internal` is a reserved schema category. Total CMS uses it for the
embedded sub-schemas that only appear as cards inside another schema and
for the reserved schemas it provisions collections from. The sidebar
collapses that group by default. Do not put your own schemas in it.

## The reference schema

Total CMS ships a reserved schema named `totalcms` that demonstrates every
built-in field type, with its settings, in one valid file. Each property's
help text names the field and says what to use it for. It is registered and
resolvable but cannot back a collection. It exists to be copied from.

Read it any of these ways:

```bash
vendor/bin/tcms schema:get totalcms --json
```

- In the admin, open the schema editor. It is under the **Internal** group.
- Over MCP, call `get_schema` with `totalcms`.
- On disk at `resources/schemas/totalcms.json` in the package, with
  `totalcms-item.json` backing its `card` and `deck` examples.

Find the property that matches the value you are modelling, copy its
definition, and adapt the label and help. The `autogen`, `calc`, `hide`,
`visibility`, `required`, `onUpdate` and `propertyOptions` settings are all
demonstrated on it, as is the `seo` card.

## Mistakes worth naming

- **A date, number, email or URL in a `text` field.** Loses validation,
  filtering and sorting.
- **A `select` for something editors will extend.** Every new category
  becomes a schema edit. Use `list` with `propertyOptions`.
- **A `list` for something that must be exactly one of a few values.**
  Use `select`.
- **An image path in a `text` field.** Use `image`. Uploads, resizing and
  `cms.render.image()` all depend on it.
- **A comma-separated string.** Use `list`.
- **Several related fields flattened onto the object** (`addressLine1`,
  `addressCity`…). Use a `card`.
- **A deck with one field per item.** Use a `list`.
- **Help text that restates the label.** Help is read by editors in the form
  and by AI agents through MCP. Say what the value is for and what good
  looks like. See [Editing Content with AI Agents](docs/mcp/agent-editing#writing-for-agents).
