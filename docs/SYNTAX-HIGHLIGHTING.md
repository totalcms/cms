# TotalCMS Syntax Highlighting

TotalCMS now includes a comprehensive, self-hosted syntax highlighting solution powered by CodeMirror. No more CDN dependencies!

## What's Included

### Built-in Assets
- **codemirror-bundle.js** - Complete CodeMirror bundle with all modes and addons
- **codemirror-totalcms.css** - Custom styling that matches TotalCMS design system
- **js-beautify** - HTML/CSS/JS formatting included

### Supported Languages
- Twig templates
- HTML/XML
- CSS/SCSS
- JavaScript/JSON
- PHP
- Markdown
- YAML
- SQL
- Shell/Bash scripts

### Themes Available
- **Elegant (light)** - Default theme for Twig editor (matches light dashboard)
- **Default (light)** - Standard light theme
- **Monokai (dark)** - Available for future dark mode
- Material variants (dark)
- Dracula (dark)
- Eclipse (light)

## Usage

### 1. Twig Playground (Built-in)
The Twig playground at `/admin/utils/twig-playground` now uses local assets with enhanced features:

- **No CDN dependencies** - Everything served locally
- **Light theme design** - Elegant light theme for Twig editor, matches dashboard
- **Vibrant syntax highlighting** - GitHub-inspired colors optimized for readability
- **Auto-formatting** - HTML output is automatically formatted
- **Persistent storage** - Code saved to localStorage
- **Keyboard shortcuts** - Ctrl/Cmd+Enter to run

### 2. Creating Custom Editors

```javascript
// Include the bundle
<script type="module" src="{{ cms.api }}/assets/codemirror-bundle.js"></script>
<link rel="stylesheet" href="{{ cms.api }}/assets/codemirror-totalcms.css">

// Create editors using the TotalCMSCodeMirror API
const twigEditor = TotalCMSCodeMirror.createTwigEditor(textareaElement, {
    theme: 'elegant', // Light theme (default)
    placeholder: 'Enter Twig code here...'
});

const htmlEditor = TotalCMSCodeMirror.createHtmlEditor(textareaElement, {
    theme: 'elegant',
    readOnly: true
});

const phpEditor = TotalCMSCodeMirror.createPhpEditor(textareaElement);
const cssEditor = TotalCMSCodeMirror.createCssEditor(textareaElement);
const jsEditor = TotalCMSCodeMirror.createJsEditor(textareaElement);
const markdownEditor = TotalCMSCodeMirror.createMarkdownEditor(textareaElement);
```

### 3. Documentation Integration

For automatic syntax highlighting in documentation:

```html
<!-- Include the documentation helper -->
<script type="module" src="{{ cms.api }}/assets/syntax-highlighting-docs.js"></script>

<!-- Use data attributes to specify language -->
<pre><code data-lang="twig">
{{ cms.imagePath('image.jpg', {w: 300, h: 200}) }}
</code></pre>

<pre data-lang="php">
<?php
namespace MyApp;
class Example {}
</pre>

<code data-syntax="css">
.my-class {
    color: oklch(var(--totalform-accent));
}
</code>
```

### 4. Manual Editor Creation

```javascript
// Direct CodeMirror usage (advanced)
const editor = window.CodeMirror.fromTextArea(textarea, {
    mode: 'twig',
    theme: 'monokai',
    lineNumbers: true,
    autoCloseBrackets: true,
    matchBrackets: true,
    foldGutter: true,
    gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter']
});
```

## Available Methods

### TotalCMSCodeMirror API

```javascript
// Editor creators
TotalCMSCodeMirror.createTwigEditor(element, options)
TotalCMSCodeMirror.createHtmlEditor(element, options)
TotalCMSCodeMirror.createCssEditor(element, options)
TotalCMSCodeMirror.createJsEditor(element, options)
TotalCMSCodeMirror.createPhpEditor(element, options)
TotalCMSCodeMirror.createMarkdownEditor(element, options)

// Utilities
TotalCMSCodeMirror.formatHtml(htmlString)
TotalCMSCodeMirror.saveToStorage(key, content)
TotalCMSCodeMirror.loadFromStorage(key, defaultValue)

// Default configuration
TotalCMSCodeMirror.defaultConfig
```

## CSS Classes

Apply these classes for consistent styling:

```css
.totalcms-editor        /* Base editor styling */
.twig-editor           /* Twig-specific colors */
.html-output-editor    /* HTML output styling */
.documentation-code-block  /* Documentation code blocks */

.editor-container      /* Container with header */
.editor-header         /* Header with title and actions */
.editor-actions        /* Action buttons */
```

## Features

### Enhanced Functionality
- **Code folding** - Collapse/expand code sections
- **Bracket matching** - Highlight matching brackets/tags
- **Auto-completion** - Bracket and tag auto-closing
- **Search/Replace** - Ctrl+F for search, Ctrl+H for replace
- **Fullscreen mode** - F11 to toggle fullscreen
- **Line wrapping** - Automatic text wrapping
- **Active line highlighting** - Current line highlighting

### TotalCMS Integration
- **Design system colors** - Uses CSS variables from variables.scss
- **Consistent theming** - Matches TotalCMS visual style
- **Responsive design** - Works on mobile devices
- **Accessibility** - Proper keyboard navigation
- **Print support** - Clean printing without controls

## Migration from CDN

If you have existing CodeMirror implementations using CDN:

1. **Remove CDN links**:
   ```html
   <!-- Remove these -->
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/...">
   <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/..."></script>
   ```

2. **Add local assets**:
   ```html
   <!-- Add these -->
   <link rel="stylesheet" href="{{ cms.api }}/assets/codemirror-totalcms.css">
   <script type="module" src="{{ cms.api }}/assets/codemirror-bundle.js"></script>
   ```

3. **Update initialization**:
   ```javascript
   // Old way
   const editor = CodeMirror.fromTextArea(textarea, options);
   
   // New way (recommended)
   const editor = TotalCMSCodeMirror.createTwigEditor(textarea, options);
   ```

## Build Process

The CodeMirror bundle is built automatically with:

```bash
composer run esbuild
# or
yarn build
```

This includes:
- All CodeMirror modes and addons
- Custom TotalCMS styling
- js-beautify for formatting
- Minification and source maps

## Benefits

✅ **No CDN dependencies** - Works offline, faster loading  
✅ **Consistent theming** - Matches TotalCMS design system  
✅ **Better performance** - Local assets, optimized bundling  
✅ **Enhanced features** - Additional themes and functionality  
✅ **Future-proof** - No external service dependencies  
✅ **Customizable** - Easy to extend and modify  
✅ **Documentation ready** - Auto-highlighting for docs  

## Browser Support

- Chrome/Edge 88+
- Firefox 85+
- Safari 14+
- Mobile browsers with ES6 module support

The syntax highlighting system is fully self-contained and ready for production use!