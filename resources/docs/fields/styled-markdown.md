---
title: "Styled Markdown"
description: "The styledmarkdown field edits Markdown in the Styled Text visual editor, with a source mode and preview. It stores Markdown source; render it with the markdown filter."
related:
  - fields/styled-text
  - twig/markdown
  - collections/storage-format
---

# Styled Markdown

The `styledmarkdown` field stores a Markdown string. It uses the same editor as [Styled Text](docs/fields/styled-text), limited to what Markdown can express, so editors get a toolbar and writers who know Markdown get the source.

Use it when the content should stay as Markdown: documentation, changelogs, or bodies in a [markdown-format collection](docs/collections/storage-format). Use [Styled Text](docs/fields/styled-text) when editors need color, alignment, or embedded video.

## Rendering

The value is Markdown source, so pass it through the [`markdown` filter](docs/twig/markdown):

```twig
{{ post.body|markdown }}
```

The filter escapes any HTML in the source. Always render the field with the filter; printing the raw value shows the Markdown source and any HTML in it.

## The editor

The toolbar offers headings, bold, italic, inline code, bullet and numbered lists, blockquotes, code blocks, horizontal rules, links, image uploads, file links, and tables. There is no color, font, alignment, or underline, because Markdown has no syntax for them.

The **Source** button on the right switches to the raw Markdown and back.

### Changing the toolbar

Add `toolbarConfig` to the field's settings to choose your own buttons, in the same group format [Styled Text](docs/fields/styled-text) uses. These extra buttons are available but not in the default toolbar: `strike`, `undo`, `redo`, `preview`, and `fullscreen`.

```json
{
  "toolbarConfig": [
    { "name": "text", "buttons": ["heading", "bold", "italic", "strike", "inlineCode"] },
    { "name": "insert", "buttons": ["link", "image"] },
    { "name": "misc", "buttons": ["preview", "codeView", "fullscreen"], "align": "right" }
  ]
}
```

`preview` shows the content rendered as HTML. It is an approximation of your site, in the same way the visual editor is. Keep `codeView` in a custom toolbar: it is how content the visual editor cannot keep gets edited.

Buttons for things Markdown cannot express, such as `underline` or `align`, do nothing in this field.

## Settings

These Styled Text settings work the same way in this field. Each links to its description on the [Styled Text](docs/fields/styled-text) page.

| Setting | What it does |
|---|---|
| [`height`, `heightMin`, `heightMax`](docs/fields/styled-text#editor-height) | The editor's height. |
| [`pasteAsPlainText`](docs/fields/styled-text#paste-behavior) | Paste without formatting. |
| [`toolbarConfig`](docs/fields/styled-text#toolbar-configuration) | The toolbar buttons and their groups. See [Changing the toolbar](docs/fields/styled-markdown#changing-the-toolbar) for the buttons this field has. |
| [`headingLevels`](docs/fields/styled-text#heading-levels) | The levels in the heading menu. The default is 2, 3 and 4. |
| [`charCounterCount`, `charCounterMax`, `wordCounterCount`, `wordCounterMax`](docs/fields/styled-text#character-and-word-counters) | Counters under the editor. They count the visible text, not the Markdown characters, and a maximum only highlights when it is exceeded. |
| [`imagePreset`, `imageUploadRules`, `fileUploadRules`](docs/fields/styled-text#upload-settings) | The preset and validation rules for uploads. |

Custom inline styles, custom inline classes, block classes, HTML snippets and the color palette do nothing in this field. Markdown has no syntax for them.

## What the visual editor changes

The visual editor reads your Markdown and writes it back in one consistent style. The first time someone edits a field in visual mode, the source is normalized:

| You wrote | It is saved as |
|---|---|
| `*` or `+` bullets | `-` bullets |
| `1)` numbering | `1.` numbering |
| `Title` underlined with `===` | `# Title` |
| `__bold__`, `_italic_` | `**bold**`, `*italic*` |
| `~~~` or indented code blocks | Backtick-fenced code blocks |
| Reference links (`[text][ref]`) | Inline links |
| A single line break | A line break with two trailing spaces |
| Blank lines between list items | A tight list |

These render the same on your site, apart from the last one: a tight list has slightly less space between its items.

A field that nobody edits is never rewritten. Opening and saving an object leaves its Markdown exactly as it was.

## Content the visual editor cannot keep

Some syntax has no equivalent in the visual editor:

- raw HTML and HTML comments
- footnotes (`[^1]`)
- abbreviations (`*[HTML]: HyperText Markup Language`)
- an image inside a link (`[![badge](badge.png)](https://example.com)`)

When a field contains any of these, it opens in source mode with a notice, and nothing is changed. You can still switch to the visual editor; it asks first, because editing there removes that syntax.

HTML inside a code block or inline code is not affected. It is kept as written in both modes. To write about a tag in running text, put it in inline code (`` `<div>` ``); a bare `<div>` typed in the visual editor is saved as raw HTML.

The visual editor also writes HTML entities as plain characters when you edit: `&amp;` becomes `&`, and `&#169;` becomes `©`.

## Images and files

Image and file uploads work as they do in Styled Text. An uploaded image is inserted as `![alt text](url)`. Image width and float options are not offered, because Markdown cannot store them.

## Sanitizing

A styledmarkdown field holds source, so it is stored exactly as written, the same as a [code field](docs/fields/code-editor). HTML in the source is not removed when the object is saved. The `markdown` filter escapes it when the page renders, which is why code samples such as `<script>` inside a code block stay intact.

If people you don't fully trust can write to the field, such as visitors using a public form, turn on `htmlclean` in the field's settings so unsafe HTML is stripped when the object is saved:

```json
{
  "htmlclean": true
}
```

With it on, `<script>` tags and event attributes are removed everywhere in the value, including inside code blocks, so leave it off for content that shows HTML code samples.

## AI agents and the API

Over the REST API and MCP the value is the Markdown string, read and written as is. Agents should send Markdown, not HTML.
