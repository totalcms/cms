---
title: "Markdown"
description: "The markdown field is a Markdown source editor with a toolbar, preview and fullscreen. It stores what you write; render it with the markdown filter."
related:
  - fields/styled-markdown
  - twig/markdown
  - collections/storage-format
---

# Markdown

The `markdown` field is a source editor: you write Markdown, and it is stored exactly as you wrote it. A toolbar inserts the syntax for you, a preview shows the result, and fullscreen gives you room for a long document.

There are two Markdown fields, and they store the same thing:

| Field | How you edit |
|---|---|
| `markdown` | You write the Markdown source. Nothing is rewritten, and footnotes, raw HTML and anything else Markdown allows are kept. |
| [`styledmarkdown`](docs/fields/styled-markdown) | A visual editor writes the Markdown for you. Best for editors who don't know Markdown. |

Because the stored value is the same, you can change a field from one to the other in the schema at any time.

## Rendering

The value is Markdown source, so pass it through the [`markdown` filter](docs/twig/markdown):

```twig
{{ post.body|markdown }}
```

The filter escapes any HTML in the source. Always render the field with the filter; printing the raw value shows the Markdown source and any HTML in it.

## The editor

In the admin the editor highlights Markdown syntax as you type. On a public form it is a plain text area with the same toolbar.

The toolbar inserts syntax at the cursor or around the selected text:

- **Headings, bold, italic, inline code.** Pressing a button again removes the formatting.
- **Bullet list, numbered list, blockquote.** These apply to every line you have selected.
- **Code block, horizontal rule, table.** These insert a block on its own lines.
- **Link.** Wraps the selected text as `[text](url)`.
- **Image and File.** Open the upload dialog and insert `![alt](url)` or `[name](url)`. Before the object has been saved for the first time, uploads are not available and the buttons insert the syntax for you to fill in.

### Preview and fullscreen

**Preview** replaces the editor with the rendered content; press it again to go back to the source. It is an approximation of your site: footnotes, abbreviations and definition lists are rendered by your site but not by the preview.

**Fullscreen** expands the editor to the whole window. In fullscreen, Preview shows the source and the rendered content side by side, and the preview follows what you type. Press Escape to leave fullscreen.

### Changing the toolbar

Add `toolbarConfig` to the field's settings to choose your own buttons, in the same group format [Styled Text](docs/fields/styled-text#toolbar-configuration) uses. The buttons are `heading`, `bold`, `italic`, `strike`, `inlineCode`, `bulletList`, `orderedList`, `blockquote`, `codeBlock`, `horizontalRule`, `link`, `image`, `file`, `table`, `preview` and `fullscreen`. `strike` is not in the default toolbar.

```json
{
  "toolbarConfig": [
    { "name": "text", "buttons": ["heading", "bold", "italic", "strike"] },
    { "name": "insert", "buttons": ["link", "image"] },
    { "name": "misc", "buttons": ["preview", "fullscreen"], "align": "right" }
  ]
}
```

## Settings

These Styled Text settings work the same way in this field. Each links to its description on the [Styled Text](docs/fields/styled-text) page.

| Setting | What it does |
|---|---|
| [`height`, `heightMin`, `heightMax`](docs/fields/styled-text#editor-height) | The editor's height. |
| [`toolbarConfig`](docs/fields/styled-text#toolbar-configuration) | The toolbar buttons and their groups. |
| [`headingLevels`](docs/fields/styled-text#heading-levels) | The levels in the heading menu. The default is 2, 3 and 4. |
| [`imagePreset`, `imageUploadRules`](docs/fields/styled-text#upload-settings) | The preset and validation rules for image uploads. |

## Sanitizing

A markdown field holds source, so it is stored exactly as written, the same as a [code field](docs/fields/code-editor). HTML in the source is not removed when the object is saved. The `markdown` filter escapes it when the page renders, which is why code samples such as `<script>` inside a code block stay intact.

If people you don't fully trust can write to the field, such as visitors using a public form, turn on `htmlclean` so unsafe HTML is stripped when the object is saved:

```json
{
  "htmlclean": true
}
```

With it on, `<script>` tags and event attributes are removed everywhere in the value, including inside code blocks, so leave it off for content that shows HTML code samples.

## AI agents and the API

Over the REST API and MCP the value is the Markdown string, read and written as is. Agents should send Markdown, not HTML.
