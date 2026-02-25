# Total CMS Twig Adapter

The Total CMS Twig Adapter provides access to all CMS data and functionality through the global `cms` variable in Twig templates.

## Configuration & Environment

```twig
{{ cms.env }}                                    {# Current environment (development, production) #}
{{ cms.config('key') }}                          {# Get config value by key #}
{{ cms.config('key', 'setting') }}               {# Get nested config setting #}
{{ cms.api }}                                    {# API base URL #}
{{ cms.dashboard }}                              {# Admin dashboard URL #}
{{ cms.login }}                                  {# Login URL #}
{{ cms.logout }}                                 {# Logout URL #}
{{ cms.domain }}                                 {# Current domain name #}
{{ cms.clearcache }}                             {# Emergency cache clear URL #}
```

## Authentication & Access Control

```twig
{# Login #}
{{ cms.login() }}                                {# Default login URL with current page redirect #}
{{ cms.login('collection') }}                    {# Collection login URL with current page redirect #}
{{ cms.login('', '') }}                          {# Default login URL with no redirect #}
{{ cms.login('', '/redirect/url') }}             {# Default login with custom redirect URL #}
{{ cms.login('collection', '/redirect/url') }}   {# Collection login with custom redirect URL #}

{# Logout #}
{{ cms.logout }}                                 {# Logout URL #}
{{ cms.logout() }}                               {# Same as above #}
{{ cms.logout('/redirect/url') }}                {# Logout with redirect URL #}
{{ cms.userData() }}                             {# Get current user data array #}
{{ cms.userLoggedIn() }}                         {# Check if user is logged in (boolean) #}
{{ cms.userLoggedIn('collection') }}             {# Check login for specific collection #}
{{ cms.userHasAccess('group') }}                 {# Check if user has access to group #}
{{ cms.userHasAccess(['group1', 'group2']) }}    {# Check multiple groups #}
{{ cms.sessionData('key') }}                     {# Get session data by key #}
{{ cms.verifyFilePassword(password, collection, id, property) }}  {# Verify file password #}
```

## Schemas

```twig
{{ cms.schemas() }}                              {# Get all schemas #}
{{ cms.reservedSchemas() }}                      {# Get built-in schemas #}
{{ cms.customSchemas() }}                        {# Get custom schemas #}
{{ cms.schemasByCategory() }}                    {# Get schemas grouped by category #}
{{ cms.schema('schemaName') }}                   {# Get specific schema definition #}
{{ cms.schemaForCollection('collection') }}      {# Get schema for a collection #}
```

## Collections

```twig
{{ cms.collections() }}                          {# Get all collections #}
{{ cms.collectionsByCategory() }}                {# Get collections grouped by category #}
{{ cms.collection('collectionName') }}           {# Get collection metadata #}
{{ cms.objectCount('collectionName') }}          {# Get number of objects in collection #}
{{ cms.objects('collectionName') }}              {# Get all objects from collection #}
{{ cms.property('collection', 'property') }}     {# Get unique values from property #}
{{ cms.objectUrl('collection', 'id') }}          {# Get URL for an object #}
```

## Object Data

```twig
{{ cms.object('collection', 'id') }}             {# Get complete object data #}
{{ cms.data('collection', 'id', 'property') }}   {# Get specific property value #}
```

## Search

```twig
{{ cms.search('collection', 'query', 'property') }}          {# Search with single property #}
{{ cms.search('collection', 'query', ['prop1', 'prop2']) }}  {# Search multiple properties #}
```

## Text Content

```twig
{{ cms.text('id') }}                             {# Get text (default collection: text) #}
{{ cms.text('id', {collection: 'custom'}) }}     {# Custom collection #}
{{ cms.text('id', {property: 'content'}) }}      {# Custom property #}

{{ cms.code('id') }}                             {# Get code snippet (default collection: code) #}
{{ cms.code('id', {collection: 'custom'}) }}     {# Custom collection #}
{{ cms.code('id', {property: 'snippet'}) }}      {# Custom property #}

{{ cms.styledtext('id') }}                       {# Get styled text (HTML) #}
{{ cms.styledtext('id', {collection: 'custom', property: 'html'}) }}
```

## Simple Data Types

```twig
{{ cms.toggle('id') }}                           {# Get boolean toggle value #}
{{ cms.toggle('id', {collection: 'settings', property: 'enabled'}) }}

{{ cms.date('id') }}                             {# Get date string #}
{{ cms.date('id', {collection: 'events', property: 'eventDate'}) }}

{{ cms.number('id') }}                           {# Get number value #}
{{ cms.number('id', {collection: 'stats', property: 'count'}) }}

{{ cms.url('id') }}                              {# Get URL #}
{{ cms.url('id', {collection: 'links', property: 'href'}) }}

{{ cms.email('id') }}                            {# Get email address #}
{{ cms.email('id', {}, true) }}                  {# Get email with HTML encoding (anti-spam) #}

{{ cms.svg('id') }}                              {# Get SVG content #}
{{ cms.svg('id', {collection: 'icons', property: 'svgData'}) }}
```

## Colors

```twig
{% set myColor = cms.color('id') %}              {# Get color data array #}
{% set myColor = cms.colour('id') %}             {# British spelling alias #}

{# Color has 'hex' and 'oklch' properties #}
{{ myColor.hex }}                                {# Hex value: #ff0000 #}
{{ myColor.oklch.l }}                            {# Lightness #}
{{ myColor.oklch.c }}                            {# Chroma #}
{{ myColor.oklch.h }}                            {# Hue #}

{# Use with color filters #}
{{ myColor|hex }}                                {# Output: #ff0000 #}
{{ myColor|oklch }}                              {# Output: oklch(62.8% 0.25768 29.234) #}
{{ myColor|rgb }}                                {# Output: rgb(255 0 0) #}
{{ myColor|hsl }}                                {# Output: hsl(0 100% 50%) #}
```

## Images

```twig
{# Basic image output #}
{{ cms.image('id') }}                            {# Returns complete <img> HTML #}
{{ cms.imagePath('id') }}                        {# Returns image URL only #}
{{ cms.alt('id') }}                              {# Get alt text #}

{# With ImageWorks transformations #}
{{ cms.image('id', {w: 800, h: 600, fit: 'crop'}) }}
{{ cms.imagePath('id', {w: 400, blur: 20, fm: 'webp'}) }}

{# Custom collections and properties #}
{{ cms.image('id', {}, {collection: 'products', property: 'photo'}) }}

{# Pass object directly (recommended) #}
{{ cms.image(object, {w: 600}, {collection: 'products', property: 'image'}) }}

{# Loading options #}
{{ cms.image('id', {}, {loading: 'eager'}) }}    {# Default is 'lazy' #}
```

## Galleries

### Standard Gallery (Grid with Lightbox)

```twig
{# Complete gallery with lightbox #}
{{ cms.gallery('id') }}                          {# Default 300x200 thumbs #}
{{ cms.gallery('id', {w: 150, h: 150}) }}        {# Custom thumb size #}
{{ cms.gallery('id', {w: 150}, {w: 1200}) }}     {# Thumb and full size settings #}

{# Gallery with options #}
{{ cms.gallery('id', {w: 200}, {}, {
    maxVisible: 8,
    viewAllText: 'Show all photos',
    loop: true,
    download: false,
    captions: true,
    gridCaptions: true,
    sort: 'name'
}) }}

{# Individual gallery images #}
{{ cms.galleryImage('id', 'filename.jpg') }}     {# Get specific image HTML #}
{{ cms.galleryPath('id', 'filename.jpg', {w: 800}) }}  {# Get image URL #}
{{ cms.galleryAlt('id', 'filename.jpg') }}       {# Get alt text #}
{{ cms.galleryImageData('id', 'filename.jpg') }} {# Get complete image data #}

{# Dynamic gallery images #}
{{ cms.galleryImage('id', 'first') }}            {# First image #}
{{ cms.galleryImage('id', 'last') }}             {# Last image #}
{{ cms.galleryImage('id', 'random') }}           {# Random image #}
{{ cms.galleryImage('id', 'featured') }}         {# Featured image #}
```

Note: `cms.galleryImage` automatically includes `data-gallery` and `data-gallery-image` attributes, making it easy to use with `cms.galleryLauncher`.

### Gallery Launcher (Trigger-Based Lightbox)

The gallery launcher allows you to open a lightbox from custom trigger elements like buttons, links, or thumbnails. Perfect for launching galleries without showing a visible grid.

```twig
{# Basic gallery launcher #}
{{ cms.galleryLauncher('id') }}                   {# Outputs hidden template with gallery data #}
<button data-gallery="gallery-id">View Photos</button>

{# Custom thumbnail and full-size settings #}
{{ cms.galleryLauncher('id', {w: 300, h: 200}, {w: 1920, fit: 'contain'}) }}

{# With options #}
{{ cms.galleryLauncher('id', {w: 300}, {w: 1920}, {
    collection: 'gallery',
    property: 'gallery',
    captions: true,                              {# Show captions in lightbox #}
    speed: 600,
    loop: true,
    download: false,
    plugins: ['zoom', 'thumbnail', 'fullscreen']
}) }}
```

#### Triggering Gallery Launchers

**Method 1: Using data-gallery attribute (recommended)**
```twig
{{ cms.galleryLauncher('vacation') }}
<button data-gallery="gallery-vacation">View All Photos</button>
```

**Method 2: Using CSS selector**
```twig
{{ cms.galleryLauncher('vacation', {}, {}, {
    trigger: '.open-gallery-btn'                 {# Any element with this class triggers gallery #}
}) }}
<button class="open-gallery-btn">View Gallery</button>
<a class="open-gallery-btn">See Photos</a>
```

**Method 3: Combined (both data-gallery and CSS selector)**
```twig
{{ cms.galleryLauncher('vacation', {}, {}, {
    trigger: '.gallery-thumb'
}) }}
<button data-gallery="gallery-vacation">View All</button>
<img class="gallery-thumb" src="thumb1.jpg">
<img class="gallery-thumb" src="thumb2.jpg">
```

#### Opening at Specific Image

**By image filename (recommended):**
```twig
{{ cms.galleryLauncher('vacation') }}

{# These thumbnails open gallery at their specific image #}
<img data-gallery="gallery-vacation"
     data-gallery-image="sunset.jpg"
     src="sunset-thumb.jpg">

<img data-gallery="gallery-vacation"
     data-gallery-image="beach.jpg"
     src="beach-thumb.jpg">
```

**By index (1-based):**
```twig
{{ cms.galleryLauncher('vacation') }}
<button data-gallery="gallery-vacation" data-gallery-index="1">First Image</button>
<button data-gallery="gallery-vacation" data-gallery-index="6">Image #6</button>
```

**Using galleryImage (recommended):**
```twig
{{ cms.galleryLauncher('vacation') }}

{# galleryImage automatically includes data-gallery and data-gallery-image attributes #}
{{ cms.galleryImage('vacation', 'sunset.jpg', {w: 300, h: 200}) }}
{{ cms.galleryImage('vacation', 'beach.jpg', {w: 300, h: 200}) }}

{# Works with dynamic selectors too #}
{{ cms.galleryImage('vacation', 'first', {w: 400}) }}
{{ cms.galleryImage('vacation', 'featured', {w: 400}) }}
```

#### Complete Gallery Launcher Example

```twig
{# Create the gallery launcher #}
{{ cms.galleryLauncher('products',
    {w: 400, h: 300},           {# Thumbnail settings #}
    {w: 1920, fit: 'contain'},  {# Full-size settings #}
    {
        captions: true,
        speed: 400,
        loop: true,
        trigger: '.product-image',
        plugins: ['zoom', 'fullscreen']
    }
) }}

{# Display product thumbnails that trigger the gallery #}
<div class="product-grid">
    <img class="product-image"
         data-gallery-image="front-view.jpg"
         src="thumb-front.jpg"
         alt="Front view">

    <img class="product-image"
         data-gallery-image="side-view.jpg"
         src="thumb-side.jpg"
         alt="Side view">

    <img class="product-image"
         data-gallery-image="detail.jpg"
         src="thumb-detail.jpg"
         alt="Close-up detail">

    <button data-gallery="gallery-products">View All Images</button>
</div>
```

**Gallery Launcher Options:**
- `trigger` - CSS selector for trigger elements (in addition to data-gallery)
- `captions` - Lightbox captions: `true` for default, or a Twig template string (see [Gallery Captions](#gallery-captions))
- `galleryId` - Custom gallery ID (default: `{collection}-{id}`)
- `sort` - Sort images by property before rendering (see [Sorting Gallery Images](#sorting-gallery-images))
- All standard LightGallery options: `speed`, `loop`, `download`, `counter`, `plugins`, etc.
- Note: Grid-specific options (`maxVisible`, `viewAllText`) don't apply to gallery launchers

**When to Use Gallery Launchers:**
- Custom thumbnail layouts that don't fit the grid pattern
- Opening galleries from buttons or text links
- Multiple different triggers for the same gallery
- Launching galleries from specific images in your custom layout
- Image-based navigation where clicking a product photo opens full gallery

### Gallery Captions

Galleries support two independent caption options:

- **`captions`** - Captions inside the lightbox overlay
- **`gridCaptions`** - Captions below thumbnails in the grid

Both accept either `true` for default behavior or a **Twig template string** for custom formatting.

#### Default Captions

When set to `true`, captions use this fallback chain: alt text → EXIF title → EXIF description. If none are available, no caption is shown. Filenames are never used as captions.

```twig
{{ cms.gallery('id', {w: 300}, {w: 1500}, {
    captions: true,
    gridCaptions: true
}) }}
```

#### Caption Templates

Pass a template string to customize caption content. Use single curly braces `{variable}` for template variables — they are automatically converted to Twig syntax before rendering. Standard Twig features (conditionals, filters) work inside the braces.

```twig
{# Simple alt text caption #}
{% set caption = "{alt}" %}
{{ cms.gallery('id', {}, {}, {captions: caption}) }}

{# Photography captions with EXIF data #}
{% set caption %}
<h4>{alt}</h4>
<p>{exif.camera} · {exif.lens}</p>
<p>f/{exif.aperture} · {exif.shutterSpeed} · ISO {exif.iso}</p>
{% endset %}
{{ cms.gallery('id', {}, {}, {captions: caption}) }}

{# Different templates for grid and lightbox #}
{% set gridCaption = "{alt}" %}
{% set lightboxCaption %}
<h4>{alt}</h4>
<p>{exif.description}</p>
<p>{exif.camera} — f/{exif.aperture}</p>
{% endset %}

{{ cms.gallery('id', {w: 300}, {w: 1500}, {
    gridCaptions: gridCaption,
    captions: lightboxCaption
}) }}
```

When a template string is used, HTML is preserved (not escaped), so you can include markup like `<h4>`, `<p>`, `<span>`, etc. When set to `true`, plain text captions are HTML-escaped.

If all template variables resolve to empty, the caption is suppressed entirely (empty HTML tags are not shown).

#### Available Template Variables

**Image fields:**

| Variable | Description |
|----------|-------------|
| `alt` | Alt text |
| `name` | Filename |
| `width` | Image width in pixels |
| `height` | Image height in pixels |
| `mime` | MIME type |
| `size` | File size in bytes |
| `link` | Optional link URL |
| `tags` | Array of tags |
| `uploadDate` | Upload date (ISO 8601) |

**EXIF metadata** (available when the image contains EXIF data):

| Variable | Description |
|----------|-------------|
| `exif.title` | Image title |
| `exif.description` | Image description |
| `exif.camera` | Camera model |
| `exif.make` | Camera manufacturer |
| `exif.lens` | Lens model |
| `exif.aperture` | f-number (e.g., 1.8) |
| `exif.shutterSpeed` | Shutter speed (e.g., 1/125) |
| `exif.iso` | ISO sensitivity |
| `exif.focalLength` | Focal length in mm |
| `exif.date` | Photo capture date |
| `exif.author` | Photographer name |
| `exif.copyright` | Copyright notice |
| `exif.city` | City |
| `exif.state` | State/Province |
| `exif.country` | Country |
| `exif.sublocation` | Specific location |
| `exif.latitude` | GPS latitude |
| `exif.longitude` | GPS longitude |
| `exif.altitude` | GPS altitude |

### Gallery Grid Styling

The gallery grid uses CSS Grid with a customizable minimum column size via the `--cms-gallery-min-size` CSS variable (default: `150px`). Smaller values produce more columns; larger values produce fewer.

```css
/* Change the minimum thumbnail size globally */
:root {
    --cms-gallery-min-size: 250px;
}

/* Or override per gallery using the class option */
.large-thumbs {
    --cms-gallery-min-size: 300px;
}
```

```twig
{{ cms.gallery('id', {}, {}, {class: 'large-thumbs'}) }}
```

### Sorting Gallery Images

By default, gallery images display in their stored order. Use the `sort` option to sort images by any image property. This works with both `cms.gallery()` and `cms.galleryLauncher()`.

#### Simple Sort (Single Property)

```twig
{# Sort alphabetically by filename #}
{{ cms.gallery('id', {}, {}, {sort: 'name'}) }}

{# Sort by upload date (oldest first) #}
{{ cms.gallery('id', {}, {}, {sort: 'uploadDate'}) }}

{# Sort by EXIF capture date (oldest first) #}
{{ cms.gallery('id', {}, {}, {sort: 'exif.date'}) }}
```

#### Reverse Sort (Descending)

Prefix the property name with `-` to sort in descending order:

```twig
{# Newest photos first (by EXIF date) #}
{{ cms.gallery('id', {}, {}, {sort: '-exif.date'}) }}

{# Most recently uploaded first #}
{{ cms.gallery('id', {}, {}, {sort: '-uploadDate'}) }}

{# Largest files first #}
{{ cms.gallery('id', {}, {}, {sort: '-size'}) }}
```

#### Multi-Criteria Sort

Pass an array of rule objects for advanced sorting with multiple criteria:

```twig
{# Featured images first, then sorted by EXIF date (newest first) #}
{{ cms.gallery('id', {}, {}, {sort: [
    {property: 'featured', reverse: true},
    {property: 'exif.date', reverse: true}
]}) }}

{# Sort by camera model, then by filename within each group #}
{{ cms.gallery('id', {}, {}, {sort: [
    {property: 'exif.camera'},
    {property: 'name'}
]}) }}
```

#### With Gallery Launcher

```twig
{{ cms.galleryLauncher('vacation', {w: 300}, {w: 1920}, {
    sort: '-exif.date',
    captions: true,
    loop: true
}) }}
```

#### Sortable Properties

| Property | Description |
|----------|-------------|
| `name` | Filename (natural sort) |
| `uploadDate` | When the image was uploaded (ISO 8601) |
| `size` | File size in bytes |
| `width` | Image width in pixels |
| `height` | Image height in pixels |
| `featured` | Boolean — useful for sorting featured images first |
| `exif.date` | Date photo was taken |
| `exif.camera` | Camera model |
| `exif.focalLength` | Focal length |
| `exif.aperture` | Aperture value |
| `exif.iso` | ISO sensitivity |

## File Downloads & Streaming

### Downloads (attachment; forces download)

```twig
{# Single file download #}
{{ cms.download('id') }}                         {# Default from 'file' collection #}
{{ cms.download('id', {collection: 'documents', property: 'pdf'}) }}
{{ cms.download('id', {pwd: 'secret123'}) }}     {# Password-protected file #}

{# Depot (multiple files) #}
{% set files = cms.depot('id') %}                {# Get files array #}
{% for file in files %}
    <a href="{{ cms.depotDownload('id', file.name) }}">{{ file.name }}</a>
{% endfor %}

{# Depot with folders #}
{{ cms.depotDownload('id', 'document.pdf', {path: 'folder/subfolder'}) }}
{{ cms.depotDownload('id', 'folder/document.pdf') }}  {# Path in filename #}
{{ cms.depotDownload('id', 'file.zip', {pwd: 'pass123'}) }}
```

### Streaming (inline; plays in browser)

```twig
{# Single file streaming (ideal for video/audio) #}
{{ cms.stream('id') }}                           {# Default from 'file' collection #}
{{ cms.stream('id', {collection: 'videos', property: 'video'}) }}
{{ cms.stream('id', {pwd: 'secret123'}) }}       {# Password-protected file #}

{# Depot file streaming #}
{{ cms.depotStream('id', 'video.mp4') }}         {# Stream specific file #}
{{ cms.depotStream('id', 'movie.mp4', {path: 'folder/subfolder'}) }}
{{ cms.depotStream('id', 'folder/video.mp4') }}  {# Path in filename #}
{{ cms.depotStream('id', 'audio.mp3', {pwd: 'pass123'}) }}

{# HTML5 video/audio examples #}
<video controls>
    <source src="{{ cms.stream('video-id') }}" type="video/mp4">
</video>

<audio controls>
    <source src="{{ cms.depotStream('audio-id', 'song.mp3') }}" type="audio/mpeg">
</audio>
```

**Stream vs Download:**
- **Stream**: Content-Disposition: inline, supports HTTP range requests, ideal for media files
- **Download**: Content-Disposition: attachment, forces download dialog
- Both support password protection and automatic encryption

## Pagination

```twig
{# Simple pagination (Previous/Next only) #}
{{ cms.paginationSimple(totalObjects, currentPage, pageLimit) }}
{{ cms.paginationSimple(items|length, page, 10, 'page', 'Prev', 'Next') }}

{# Full pagination with page numbers #}
{{ cms.paginationFull(totalObjects, currentPage, pageLimit) }}
{{ cms.paginationFull(items|length, page, 10, 'p', '← Previous', 'Next →', {sort: 'date'}) }}
```

## URL Helpers

```twig
{{ cms.prettyUrl('/blog/post.php') }}            {# Convert to pretty URL #}
{{ cms.apacheRule(currentUrl, 'Blog Posts') }}   {# Generate .htaccess rules #}
{{ cms.nginxRule(currentUrl, 'Products') }}      {# Generate nginx rules #}
```

## Form Builder Integration

```twig
{{ cms.form.render('formId') }}                  {# Render complete form #}
{{ cms.form.field('fieldType', 'name', 'value', {options}) }}  {# Individual field #}
```

## Grid Renderer

The grid renderer provides helper methods for content grids:

```twig
{{ cms.grid.date(item, 'M j, Y') }}              {# Format date with fallback #}
{{ cms.grid.tags(item, '/blog/tag') }}           {# Render tag list with links #}
{{ cms.grid.excerpt(item, 160) }}                {# Generate excerpt #}
{{ cms.grid.price(item) }}                       {# Format price #}
{{ cms.grid.meta(item) }}                        {# Render metadata (author, date, etc) #}
```

## Render Helpers

```twig
{# Load more items from a collection (HTMX-powered pagination) #}
{{ cms.render.loadMore('blog', {template: 'blog/card.twig', limit: 10}) }}

{# Load more items from a DataView #}
{{ cms.render.loadMoreDataView('recent-posts', {template: 'blog/card.twig', limit: 10}) }}
```

See [Load More Documentation](docs/collections/load-more) for full options and examples.

## Server & Diagnostics

```twig
{{ cms.checker.serverInfo() }}                   {# Server information array #}
{{ cms.checker.checkRequiredSoftware() }}        {# Required software check #}
{{ cms.checker.checkOptionalSoftware() }}        {# Optional software check #}
{{ cms.checker.getVersion() }}                   {# Total CMS version #}

{{ cms.cacheReporter.getStatus() }}              {# Cache status #}
{{ cms.logAnalyzer.getRecentErrors(10) }}             {# Recent error logs #}
```

## Job Queue

```twig
{{ cms.processJobQueueCommand() }}               {# Get CLI command for processing jobs #}
{{ cms.jobQueuePendingInfo() }}                  {# HTML table of pending jobs #}
{{ cms.jobQueueFailedInfo() }}                   {# HTML table of failed jobs #}
```

## Logging

Log messages from your templates to help with debugging. Messages are written to `twig.log` and viewable in the admin log analyzer.

```twig
{{ cms.log('Something unexpected happened') }}
{{ cms.log('Missing image for product', 'error') }}
{{ cms.log('Debug info', 'debug', { id: object.id }) }}
```

### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `message` | string | required | The message to log |
| `level` | string | `'warning'` | Log level: `debug`, `info`, `warning`, `error` |
| `context` | object | `{}` | Additional context data to include in the log entry |

## Utility Functions

```twig
{{ cms.redirectIfNotFound(object) }}             {# Redirect if object is empty #}
{{ cms.languages() }}                            {# Get supported languages array #}
```

## ImageWorks Parameters

Common parameters for image transformations:

### Basic Image Controls
- `w` - Width in pixels
- `h` - Height in pixels  
- `fit` - How to fit image: `contain`, `max`, `fill`, `stretch`, `crop`
- `crop` - Crop position: `top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`
- `fm` - Output format: `jpg`, `png`, `gif`, `webp`, `avif`
- `q` - Quality (1-100)

### Effects & Filters
- `blur` - Blur amount (0-100)
- `sharp` - Sharpen amount (0-100)
- `pixel` - Pixelate amount (0-100)
- `filt` - Filter: `greyscale`, `sepia`

### Image Watermarks
- `mark` - Watermark image path
- `markw` - Watermark width
- `markh` - Watermark height
- `markpos` - Watermark position
- `markpad` - Watermark padding
- `markalpha` - Watermark opacity (0-100)

### Text Watermarks
- `marktext` - Text to display as watermark
- `marktextfont` - Font family name (TTF/OTF fonts from watermark-fonts depot)
- `marktextsize` - Text size in pixels (default: 500)
- `marktextcolor` - Text color as hex (without #, e.g., 'ffffff' for white)
- `marktextbg` - Background color as hex (optional, transparent if not set)
- `marktextpad` - Padding around text in pixels (default: 10)
- `marktextangle` - Text rotation angle in degrees (-360 to 360, default: 0)
- `marktextpos` - Text position: `top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`
- `marktextw` - Maximum text width in pixels or relative (e.g., '50w' for 50% of image width)
- `marktextalpha` - Text transparency (0-100, where 100 is fully opaque)

## Examples

### Display a blog post
```twig
{% set post = cms.object('blog', 'my-post-id') %}
<article>
    <h1>{{ post.title }}</h1>
    <time>{{ post.date|dateRelative }}</time>
    {{ post.content|markdown }}
    {{ cms.image(post.id, {w: 800, h: 400, fit: 'crop'}) }}
</article>
```

### Create an image gallery
```twig
{% set product = cms.object('products', 'widget-pro') %}
<div class="product-gallery">
    {{ cms.gallery(product.id, {w: 100, h: 100}, {w: 1200}, {
        maxVisible: 4,
        viewAllText: 'View all images'
    }) }}
</div>
```

### Create a gallery launcher with custom triggers
```twig
{% set product = cms.object('products', 'widget-pro') %}

{# Output the gallery launcher (hidden template) #}
{{ cms.galleryLauncher(product.id, {w: 300, h: 300}, {w: 1920}, {
    captions: true,
    trigger: '.product-thumb',
    plugins: ['zoom', 'fullscreen']
}) }}

{# Display custom layout with gallery triggers #}
<div class="product-images">
    <img class="product-thumb main-image"
         data-gallery-image="front.jpg"
         src="{{ cms.galleryPath(product.id, 'front.jpg', {w: 600, h: 400}) }}">

    <div class="thumbnail-strip">
        <img class="product-thumb"
             data-gallery-image="side.jpg"
             src="{{ cms.galleryPath(product.id, 'side.jpg', {w: 100, h: 100}) }}">
        <img class="product-thumb"
             data-gallery-image="detail.jpg"
             src="{{ cms.galleryPath(product.id, 'detail.jpg', {w: 100, h: 100}) }}">
    </div>

    <button data-gallery="gallery-{{ product.id }}">View All Photos</button>
</div>
```

### Protected downloads
```twig
{% if cms.verifyFilePassword(password, 'documents', docId, 'file') %}
    <a href="{{ cms.download(docId, {pwd: password}) }}">Download Document</a>
{% else %}
    <p>Invalid password</p>
{% endif %}
```

### Display object counts efficiently
```twig
{# Efficient: Uses cached collection metadata (no index loading) #}
<p>{{ cms.objectCount('blog') }} blog posts</p>
<p>{{ cms.objectCount('products') }} products available</p>

{# Avoid: Loads entire index just to count objects #}
<p>{{ cms.objects('blog')|length }} blog posts</p>
```

### Search with pagination
```twig
{% set results = cms.search('blog', query, ['title', 'content', 'tags']) %}
{% set page = app.request.get('page', 1) %}
{% set perPage = 10 %}
{% set paged = results|paginate(perPage, page) %}

{% for item in paged %}
    <article>{{ item.title }}</article>
{% endfor %}

{{ cms.paginationFull(results|length, page, perPage) }}
```

### Text watermark examples
```twig
{# Simple text watermark #}
{{ cms.imagePath('hero-image', {
    w: 1200, 
    h: 600, 
    marktext: 'Copyright 2024'
}) }}

{# Styled text watermark with custom font #}
{{ cms.imagePath('product-photo', {
    w: 800,
    marktext: 'Premium Quality',
    marktextfont: 'Dorsa-Regular',
    marktextsize: 120,
    marktextcolor: 'ffffff',
    marktextbg: '000000',
    marktextpad: 20,
    marktextpos: 'bottom-right',
    marktextalpha: 80
}) }}

{# Rotated watermark #}
{{ cms.imagePath('landscape', {
    marktext: 'DRAFT',
    marktextsize: 200,
    marktextangle: -45,
    marktextcolor: 'ff0000',
    marktextpos: 'center',
    marktextalpha: 50
}) }}

{# Responsive text width #}
{{ cms.imagePath('banner', {
    w: 1200,
    marktext: 'This is a very long watermark text that will wrap',
    marktextw: '80w',  {# 80% of image width #}
    marktextpos: 'top'
}) }}
```