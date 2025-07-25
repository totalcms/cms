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
{{ cms.login() }}                                {# Default login URL #}
{{ cms.login('collection') }}                    {# Collection-specific login URL #}
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
{{ cms.schema('schemaName') }}                   {# Get specific schema definition #}
{{ cms.schemaForCollection('collection') }}      {# Get schema for a collection #}
```

## Collections

```twig
{{ cms.collections() }}                          {# Get all collections #}
{{ cms.collectionsByCategory() }}                {# Get collections grouped by category #}
{{ cms.collection('collectionName') }}           {# Get collection metadata #}
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

{# Create image from existing data #}
{{ cms.imageFromData(imageData, 'id', {w: 600}) }}

{# Loading options #}
{{ cms.image('id', {}, {loading: 'eager'}) }}    {# Default is 'lazy' #}
```

## Galleries

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
    download: false
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

## Server & Diagnostics

```twig
{{ cms.checker.serverInfo() }}                   {# Server information array #}
{{ cms.checker.checkRequiredSoftware() }}        {# Required software check #}
{{ cms.checker.checkOptionalSoftware() }}        {# Optional software check #}
{{ cms.checker.getVersion() }}                   {# Total CMS version #}

{{ cms.cacheReporter.getStatus() }}              {# Cache status #}
{{ cms.logger.getRecentErrors(10) }}             {# Recent error logs #}
```

## Job Queue

```twig
{{ cms.processJobQueueCommand() }}               {# Get CLI command for processing jobs #}
{{ cms.jobQueuePendingInfo() }}                  {# HTML table of pending jobs #}
{{ cms.jobQueueFailedInfo() }}                   {# HTML table of failed jobs #}
```

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
- `marktextfont` - Font family name (loaded from watermark-fonts depot)
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

### Protected downloads
```twig
{% if cms.verifyFilePassword(password, 'documents', docId, 'file') %}
    <a href="{{ cms.download(docId, {pwd: password}) }}">Download Document</a>
{% else %}
    <p>Invalid password</p>
{% endif %}
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