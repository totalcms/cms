# Total CMS Frontend Architecture Guide

This guide provides comprehensive documentation for the JavaScript/frontend architecture and build system of Total CMS.

## Table of Contents

1. [Overview](#overview)
2. [Build System](#build-system)
3. [TotalForm System](#totalform-system)
4. [Component Architecture](#component-architecture)
5. [Field Types](#field-types)
6. [Asset Management](#asset-management)
7. [Third-Party Integrations](#third-party-integrations)
8. [Development Workflow](#development-workflow)
9. [Performance Optimization](#performance-optimization)
10. [Browser Support](#browser-support)

## Overview

Total CMS uses a modern frontend architecture built around:

- **ESBuild**: Ultra-fast bundling and compilation
- **ES Modules**: Native JavaScript module system
- **Component-Based Architecture**: Modular, reusable JavaScript components
- **Progressive Enhancement**: Works without JavaScript, enhanced with it
- **Responsive Design**: Mobile-first approach with adaptive interfaces

### Technology Stack

- **Build Tool**: ESBuild 0.16.17
- **Module System**: ES6 Modules with ESM format
- **Styling**: SCSS with Sass Extended Importer
- **Code Editor**: CodeMirror 5.65
- **Rich Text**: Tiptap 3.x
- **File Upload**: Dropzone 7.1.1
- **Data Tables**: Native HTML tables with CSS sticky headers and vanilla JS sorting
- **Image Gallery**: LightGallery 2.8.2
- **Select Enhancement**: Choices.js 11.0.4

## Build System

### ESBuild Configuration

The build system uses two separate ESBuild processes:

#### JavaScript Build (`esbuild.config.js`)

```javascript
// Entry points for different sections
entryPoints: [
    "javascript/admin.js",      // Admin interface
    "javascript/gallery.js",    // Image gallery
    "javascript/content.js",    // Content management
    "javascript/filelinks.js",  // File link management
    "javascript/imageworks-builder.js", // Image processing
    "javascript/totalcms.js",   // Core CMS functionality
    "javascript/swagger.js",    // API documentation
]

// Build configuration
format: "esm",              // ES modules
platform: "browser",       // Browser environment
bundle: true,              // Bundle dependencies
minify: true,              // Minification
splitting: true,           // Code splitting
sourcemap: true,           // Source maps for debugging
target: "esnext",          // Modern JavaScript
outdir: 'public/assets'    // Output directory
```

#### CSS Build Process

```javascript
// SCSS processing with advanced features
entryPoints: ["css/*.scss"]
plugins: [
    sassPlugin({
        loadPaths: [
            "node_modules/froala-editor/css/",
            "node_modules/codemirror/lib/",
            "node_modules/dropzone/src/",
            "css/lightgallery/",

        ],
        importer: createImporter(), // Extended import resolution
    })
]
```

### Build Commands

```bash
# Development build with watch
bin/watch.sh

# Production build
yarn build
composer run esbuild

# Asset-only build
bin/build-assets.sh

# Full application build
composer run build
```

### Asset Pipeline

1. **Source Processing**: SCSS compilation with import resolution
2. **Bundling**: JavaScript modules bundled with dependencies
3. **Code Splitting**: Automatic chunk splitting for optimal loading
4. **Minification**: Production-ready compressed assets
5. **Source Maps**: Debug-friendly source mapping
6. **File Copying**: Static assets (fonts, images) copied to output

## TotalForm System

The TotalForm system is the core of Total CMS's frontend architecture, providing a modular form builder with extensive field types.

### Architecture Overview

```
TotalForm (Main Controller)
├── TotalField (Base Field Class)
├── TotalDispatcher (Event System)
├── TotalFormManager (Global Manager)
└── Field Types (Specialized Components)
    ├── Identifier (ID generation)
    ├── StyledText (Rich text editor)
    ├── Image (Image management)
    ├── Gallery (Multi-image)
    ├── File (File uploads)
    └── ... (20+ field types)
```

### Core Components

#### TotalForm (`totalform/totalform.js`)

Main form controller that orchestrates all field types:

```javascript
import TotalForm from './totalform/totalform';

// Initialize form
const form = new TotalForm(formElement, {
    actions: {
        save: true,
        delete: true,
        duplicate: true
    },
    validation: true,
    autosave: false
});
```

#### TotalField (`totalform/totalfield.js`)

Base class for all field types:

```javascript
export default class TotalField {
    constructor(container, options) {
        this.container = container;
        this.form = container.closest('form');
        this.input = container.querySelector('input, select, textarea');
        this.options = Object.assign({}, defaults, options);
        
        this.init();
        this.bindEvents();
    }
    
    // Core methods all fields inherit
    getValue() { /* ... */ }
    setValue(value) { /* ... */ }
    validate() { /* ... */ }
    disable() { /* ... */ }
    enable() { /* ... */ }
}
```

#### TotalDispatcher (`totalform/dispatcher.js`)

Event system for field communication:

```javascript
// Field registration
TotalDispatcher.register('field-change', (data) => {
    // Handle field changes globally
});

// Event dispatch
TotalDispatcher.dispatch('field-change', {
    field: this,
    value: newValue,
    oldValue: oldValue
});
```

## Component Architecture

### Modular Design

Each component is self-contained with:

- **Initialization**: Automatic discovery and setup
- **Event Handling**: Isolated event management
- **State Management**: Local state with global coordination
- **Cleanup**: Proper disposal and memory management

### Component Lifecycle

```javascript
class ComponentExample extends TotalField {
    constructor(container, options) {
        super(container, options);
        // Component-specific initialization
    }
    
    init() {
        // Setup component state
        this.setupUI();
        this.loadData();
    }
    
    bindEvents() {
        // Event listener setup
        this.input.addEventListener('change', this.handleChange.bind(this));
        document.addEventListener('global-event', this.handleGlobal.bind(this));
    }
    
    destroy() {
        // Cleanup when component is removed
        this.removeEventListeners();
        this.clearTimers();
    }
}
```

### Global Initialization

Components are auto-discovered and initialized:

```javascript
// admin.js - Main initialization
document.addEventListener("DOMContentLoaded", event => {
    const manager = new TotalFormManager();
    
    // Auto-initialize all form components
    const simpleForms = Array.from(document.getElementsByClassName("simple-form"));
    simpleForms.forEach(form => new SimpleForm(form));
    
    // Initialize specialized components
    const adminTables = Array.from(document.getElementsByClassName("admin-table"));
    adminTables.forEach(table => new AdminTable(table));
});
```

## Field Types

Total CMS includes 20+ specialized field types:

### Core Field Types

#### Identifier Field (`identifier.js`)
- Auto-generates slugs from other fields
- Handles edit mode (read-only when editing)
- Custom slugify configuration

```javascript
// Auto-generation from title field
autogenId() {
    const titleField = this.form.querySelector('[name="title"]');
    if (titleField) {
        return slugify(titleField.value, {
            lower: true,
            strict: true,
            remove: /[*+~.()'"!:@]/g
        });
    }
}
```

#### StyledText Field (`styledtext.js`)
- Froala rich text editor integration
- Custom toolbar configuration
- Image upload handling
- HTML sanitization

```javascript
// Froala initialization with custom config
this.editor = new FroalaEditor(this.input, {
    toolbarButtons: this.getToolbarConfig(),
    imageUploadURL: '/upload',
    imageUploadParams: { collection: 'styledtext' },
    events: {
        'contentChanged': () => this.handleChange()
    }
});
```

#### Image Field (`image.js`)
- Drag & drop upload
- Image preview with metadata
- Cropping and resizing options
- Multiple format support

#### List Field (`list.js`)
- Choices.js integration
- Dynamic option loading
- Multi-select capabilities
- Custom option creation

```javascript
// Choices.js initialization
this.choices = new Choices(this.input, {
    removeItemButton: true,
    duplicateItemsAllowed: false,
    addChoices: true,
    maxItemCount: this.options.maxItemCount || -1
});
```

### Advanced Field Types

#### Gallery Field (`gallery.js`)
- Multi-image management
- Drag & drop reordering
- Bulk upload support
- LightGallery integration

#### Depot Field (`depot.js`)
- File management system
- Multiple file uploads
- File type validation
- Download management

#### Properties Field (`properties.js`)
- Dynamic key-value pairs
- Schema-based validation
- Nested object support
- Real-time validation

### Data Fields

#### JSON Field (`json.js`)
- CodeMirror JSON editor
- Syntax highlighting
- Schema validation
- Auto-formatting

#### Date Field (`date.js`)
- Native date picker enhancement
- Timezone handling
- Custom date formats
- Range validation

## Asset Management

### Static Assets

```javascript
// Asset copying in build process
copy.default({assets: {
    from: "node_modules/lightgallery/fonts/*",
    to: "gallery"
}})
```

### Dynamic Loading

```javascript
// Lazy loading for heavy components
async loadCodeMirror() {
    if (!window.CodeMirror) {
        await import('codemirror');
        await import('codemirror/mode/javascript/javascript');
    }
    return window.CodeMirror;
}
```

### Resource Management

- **Fonts**: Web fonts loaded on-demand
- **Images**: Optimized and cached
- **Icons**: SVG sprite system
- **Scripts**: Code splitting for performance

## Third-Party Integrations

### CodeMirror Integration

Used for code editing in multiple contexts:

```javascript
// Twig template editor
const editor = CodeMirror.fromTextArea(textarea, {
    mode: 'twig',
    theme: 'monokai',
    lineNumbers: true,
    autoCloseBrackets: true,
    matchBrackets: true,
    viewportMargin: Infinity // No scrollbars
});
```

### Choices.js Integration

Enhanced select fields with search and creation:

```javascript
// Multi-select with custom options
const choices = new Choices(select, {
    removeItemButton: true,
    duplicateItemsAllowed: false,
    addChoices: true,
    searchEnabled: true,
    position: 'bottom'
});

// Load selected values properly
choices.setChoiceByValue(this.selectedValues());
```

### Froala Editor Integration

Rich text editing with customization:

```javascript
// Custom Froala configuration
const froalaConfig = {
    toolbarButtons: {
        'moreText': ['bold', 'italic', 'underline', 'strikeThrough'],
        'moreParagraph': ['alignLeft', 'alignCenter', 'alignRight', 'alignJustify'],
        'moreRich': ['insertLink', 'insertImage', 'insertTable'],
        'moreMisc': ['undo', 'redo', 'fullscreen', 'html']
    },
    imageUploadParams: { collection: 'styledtext' },
    imageMaxSize: 1048576, // 1MB
    pastePlain: true
};
```

### LightGallery Integration

Image gallery with touch support:

```javascript
// Gallery initialization
lightGallery(galleryElement, {
    plugins: [lgZoom, lgThumbnail, lgRotate],
    speed: 500,
    thumbnail: true,
    animateThumb: true,
    zoomFromOrigin: false,
    allowMediaOverlap: true,
    toggleThumb: true
});
```

## Development Workflow

### Local Development

```bash
# Start development with file watching
bin/watch.sh

# This starts:
# 1. ESBuild in watch mode
# 2. SCSS compilation watching
# 3. Auto-refresh on changes
```

### Hot Reloading

The watch system provides near-instant rebuilds:

```bash
# Watch script monitors:
javascript/**/*.js     # JavaScript files
css/**/*.scss         # SCSS stylesheets
resources/templates   # Twig templates (triggers reload)
```

### Debugging

#### Source Maps
All builds include source maps for debugging:

```javascript
// ESBuild source map configuration
sourcemap: true,
keepNames: true,  // Preserve function names for debugging
```

#### Console Integration
Components provide debug information:

```javascript
// Debug mode logging
if (this.options.debug) {
    console.log('TotalForm initialized:', this);
    console.log('Available fields:', this.fields);
}
```

### Testing JavaScript

```javascript
// Component testing pattern
describe('TotalForm Component', () => {
    let form, container;
    
    beforeEach(() => {
        container = document.createElement('form');
        container.innerHTML = '<input name="test" />';
        document.body.appendChild(container);
        
        form = new TotalForm(container);
    });
    
    afterEach(() => {
        form.destroy();
        document.body.removeChild(container);
    });
    
    it('initializes correctly', () => {
        expect(form.fields.length).toBeGreaterThan(0);
    });
});
```

## Performance Optimization

### Code Splitting

ESBuild automatically splits code into chunks:

```javascript
// Entry point separation
entryPoints: [
    "javascript/admin.js",      // Admin-only features
    "javascript/content.js",    // Content viewing
    "javascript/gallery.js",   // Gallery-specific
]

splitting: true,  // Automatic chunk splitting
```

### Lazy Loading

Heavy components load on-demand:

```javascript
// Lazy load rich text editor
async initializeEditor() {
    if (!this.editorLoaded) {
        await import('./styledtext');
        this.editorLoaded = true;
    }
}
```

### Memory Management

```javascript
// Proper cleanup to prevent memory leaks
destroy() {
    // Remove event listeners
    this.removeEventListeners();
    
    // Clear timers
    if (this.saveTimer) {
        clearTimeout(this.saveTimer);
    }
    
    // Destroy third-party components
    if (this.choices) {
        this.choices.destroy();
    }
    
    // Clear references
    this.form = null;
    this.container = null;
}
```

### Asset Optimization

- **Minification**: All production assets minified
- **Tree Shaking**: Unused code automatically removed
- **Compression**: Gzip compression for text assets
- **Caching**: Long-term caching with cache busting

## Browser Support

### Target Browsers

- **Chrome**: 90+
- **Firefox**: 88+
- **Safari**: 14+
- **Edge**: 90+

### Progressive Enhancement

```javascript
// Feature detection
if ('IntersectionObserver' in window) {
    // Use modern lazy loading
    this.setupIntersectionObserver();
} else {
    // Fallback to immediate loading
    this.loadAllImages();
}
```

### Polyfills

```javascript
// Essential polyfills included
import 'core-js/features/promise';
import 'core-js/features/array/includes';
import 'core-js/features/object/assign';
```

### Graceful Degradation

All interfaces work without JavaScript:

- Forms submit traditionally without AJAX
- Navigation works with standard links
- Content displays without dynamic loading

## File Structure

```
javascript/
├── admin.js                 # Admin interface entry point
├── content.js              # Content viewing entry point
├── gallery.js              # Gallery functionality
├── totalcms.js             # Core CMS functionality
├── totalform/              # Form system components
│   ├── totalform.js        # Main form controller
│   ├── totalfield.js       # Base field class
│   ├── totalform-manager.js # Global form manager
│   ├── dispatcher.js       # Event system
│   ├── identifier.js       # ID field automation
│   ├── styledtext.js       # Rich text editor
│   ├── image.js           # Image field
│   ├── gallery.js         # Gallery field
│   ├── list.js            # List/select field
│   ├── json.js            # JSON editor field
│   ├── properties.js      # Key-value properties
│   └── ... (other fields)
├── quickaction.js          # Quick action buttons
├── clipboard-button.js     # Clipboard functionality
├── jobqueue-stats.js       # Job queue monitoring
└── macro-builder.js        # Macro generation

css/
├── admin.scss              # Admin interface styles
├── content.scss            # Content viewing styles
├── components/             # Component-specific styles
│   ├── _forms.scss
│   ├── _tables.scss
│   ├── _modals.scss
│   └── ...
└── vendor/                 # Third-party overrides
```

This frontend architecture provides a robust, maintainable, and performant foundation for Total CMS's user interface, combining modern development practices with progressive enhancement principles.