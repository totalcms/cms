---
title: "Twig Playground"
description: "Write Twig in the admin, render it against your real content, and keep the snippets that work. The Playground is where you learn Total CMS Twig and prototype a template before it goes into a page."
related:
  - twig/overview
  - twig/recipes
  - twig/collections
  - auth/access-groups
updated: 2026-09-10
---

# Twig Playground

The Playground is a Twig editor inside the admin that renders whatever you type against the live data of your site. Open **Playground** in the sidebar, write a template, press **Render Twig**, and the output appears underneath: first as rendered HTML, then as the formatted source. Nothing you render is written anywhere, so it is a safe place to try a filter, check what a collection returns, or build a block of markup before it goes into a page template.

Everything available in a page template is available here: the `cms` global, every filter and function, `{% cmsgrid %}`, and any Twig functions or filters an extension registered.

---

## Rendering a snippet

1. Open **Playground** in the admin sidebar.
2. Type Twig into the **Twig Template Code** editor. It is the same code editor used by code fields, with Twig syntax highlighting, bracket matching and folding.
3. Press **Render Twig**.

The results section shows two things:

- **Rendered HTML** — the output as a browser would display it, so a rendered image or a styled list looks like what a page would show.
- **HTML** — the same output as formatted source in a read-only editor, so you can see the exact markup and copy it.

A Twig error renders in place of the output with the message from the engine, which usually names the line and the filter or variable that failed.

```twig
{% set posts = cms.collection.objects('blog') %}
<ul>
	{% for post in posts|sortBy('date', 'desc')|slice(0, 5) %}
		<li>{{ post.title }} — {{ post.date|date('F j, Y') }}</li>
	{% endfor %}
</ul>
```

To inspect a value rather than render it, `dump()` prints the structure of any variable:

```twig
{{ dump(cms.collection.object('blog', 'hello-world')) }}
```

---

## Saving snippets

A snippet you want to keep gets a **Name** and, optionally, a **Category**, then **Save**. The Save button only appears once the name field has a value, so an unnamed experiment cannot be saved by accident.

Saved snippets are listed in the Playground sidebar, grouped by category and filtered by the search box at the top. Each entry has a copy button that puts the snippet's Twig on the clipboard, which is the quickest way to move a working block into a page template. Opening a saved snippet loads it into the editor; editing and saving again updates it in place, and the delete button removes it.

Snippets are objects in a reserved `playground` collection, using the `playground` schema (`id`, `name`, `category`, `snippet`). The collection is created the first time someone opens the Playground. Because it is a collection, the usual tooling applies:

- **Export and Import** from the actions menu (the three dots next to the page title) move snippets between installs as JSON.
- The REST API exposes them at `/api/playground` (list, create, fetch, update, delete), gated by the same permission as the page.
- `tcms push` and `tcms pull` never move the collection: it is a per-install scratchpad, not site content. See [Sync](docs/operations/sync).

---

## Who can use it

Access is a single boolean on an access group, `playground`, alongside the other admin-page permissions. A user whose groups do not grant it does not see the sidebar entry, and the page and its API return 403. See [Access Groups](docs/auth/access-groups).

The Playground renders with the permissions of the logged-in user, so a template that reads a collection the user cannot access fails the same way it would on a page.

---

## What the Playground is not

- **Not a preview of a page.** It renders a template string on its own, outside any layout, so there is no `page` variable, no page-level SEO card and no `{% extends %}` context. To test a page template, edit the page in Site Builder.
- **Not a place for side effects.** Rendering is read-only; the engine does not write objects. A snippet that calls a form action or a render function still produces markup only.
- **Not a content editor.** Rendered output is discarded. To store the result of a template, save the template into a page or a `styledtext` field instead.

---

## Tips

- Keep one snippet per collection with the `dump()` of a typical object. When a schema changes, re-render it and the new shape is right there.
- Prototype a `{% cmsgrid %}` or a `loadMoreButton` block here first; both render in the Playground exactly as they do on a page.
- The HTML view is formatted by the same beautifier used elsewhere in the admin, so it is a fair copy to paste into a static file or a Stacks template.
