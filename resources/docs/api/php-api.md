# PHP API

The `TotalCMS` class is the main entry point for using Total CMS from PHP. It can be used both for rendering pages with Twig templates and for writing standalone CLI automation scripts.

## Page Rendering

The simplest way to build a page is to initialize `TotalCMS` at the top of your PHP file, write your HTML with Twig macros inline, and call `processBufferMacros()` at the very end. The constructor starts output buffering automatically, so everything between the opening PHP tag and the final `processBufferMacros()` call is captured and processed through Twig.

Custom templates should go into `tcms-data/templates`. Global templates that can be used include `totalform.twig`.

### Basic Page Structure

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
$totalcms = new TotalCMS\TotalCMS();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ cms.text('sitetitle') }} - My Site</title>
    <link rel="stylesheet" href="{{ cms.api }}/assets/content.css?v={{ cms.version }}"/>
</head>
<body>

    <h1>{{ cms.text('sitetitle') }}</h1>

    <!-- Display an image with resize options -->
    <div class="hero">
        {{ cms.render.image('hero', {w: 1200, h: 400, fit: 'crop'}) }}
    </div>

    <!-- List objects from a collection -->
    {% set posts = cms.collection.objects('blog', {featured: true}) | slice(0, 3) %}
    {% for post in posts %}
    <article>
        <h2>{{ post.title }}</h2>
        <span>By {{ post.author }} • {{ post.date | date('M j, Y') }}</span>
        <p>{{ post.summary | striptags | truncate(150) }}</p>
        {% if post.tags | length > 0 %}
            <span class="badge">{{ post.tags[0] }}</span>
        {% endif %}
        {{ cms.render.image(post.id, {w: 400, h: 200, fit: 'crop'}, {collection: 'blog'}) }}
    </article>
    {% endfor %}

    <!-- Gallery with lightbox -->
    {{ cms.render.gallery('photos', {
        columns: 3,
        gap: 1.5,
        lightbox: true,
        thumbnailWidth: 400,
        thumbnailHeight: 300,
        thumbnailFit: 'crop'
    }) }}

    <!-- CMS Grid for structured layouts -->
    {% cmsgrid cms.collection.objects('blog') | slice(0, 5) from 'blog' with 'list' %}
        <div class="cms-image">
            {{ cms.render.image(object.id, {w: 400, h: 400, fit: 'crop'}, {collection: 'blog'}) }}
        </div>
        <div class="cms-content">
            <h3>{{ object.title }}</h3>
            <p>{{ object.summary | striptags | truncate(200) }}</p>
        </div>
    {% endcmsgrid %}

    <!-- Various field types -->
    <p>Email: {{ cms.email('contact') }}</p>
    <p>URL: <a href="{{ cms.url('website') }}">{{ cms.url('website') }}</a></p>
    <p>Price: ${{ cms.number('price') }}</p>
    <p>Date: {{ cms.date('published') | date('F j, Y') }}</p>
    <p>Color: {{ cms.color('brand') | hex }}</p>
    <p>Toggle: {% if cms.toggle('active') %}Enabled{% else %}Disabled{% endif %}</p>
    <div>{{ cms.styledtext('about') }}</div>

    <!-- Total CMS Scripts -->
    <script type="module" src="{{ cms.api }}/assets/content.js?v={{ cms.version }}"></script>
    <script type="module" src="{{ cms.api }}/assets/gallery.js?v={{ cms.version }}"></script>
</body>
</html>

<?php echo $totalcms->processBufferMacros(); ?>
```

### Reducing Time to First Byte

For large pages, you can flush the `<head>` early so the browser can start loading stylesheets and scripts while the rest of the page is still rendering:

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
$totalcms = new TotalCMS\TotalCMS();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>{{ cms.text('sitetitle') }}</title>
    <link rel="stylesheet" href="{{ cms.api }}/assets/content.css?v={{ cms.version }}"/>
</head>

<?php
// Flush the head to the browser immediately
echo $totalcms->processBufferMacros();
$totalcms->startBuffer();
?>

<body>
    <!-- Rest of page content... -->
</body>
</html>

<?php echo $totalcms->processBufferMacros(); ?>
```

### Common Twig Variables and Functions

```twig
<!-- Global CMS variables -->
{{ cms.api }}           {# API base URL #}
{{ cms.version }}       {# Total CMS version #}
{{ cms.env }}           {# Current environment #}
{{ cms.config('key') }} {# Configuration values #}

<!-- Content functions -->
{{ cms.text('id') }}
{{ cms.email('id') }}
{{ cms.url('id') }}
{{ cms.number('id') }}
{{ cms.date('id') }}
{{ cms.color('id') }}
{{ cms.toggle('id') }}
{{ cms.styledtext('id') }}
{{ cms.render.image('id', {w: 600, h: 500}) }}
{{ cms.media.imagePath('id', {w: 600, h: 500}) }}
{{ cms.render.alt('id') }}
{{ cms.render.gallery('id', {columns: 3}) }}

<!-- Collection access -->
{% set objects = cms.collection.objects('collection') %}
{% set object = cms.collection.object('collection', 'id') %}
{% set values = cms.collection.property('collection', 'field') %}
{{ cms.data('collection', 'id', 'field') }}

<!-- Depot files -->
{% for file in cms.depot('id') %}
    {{ file.name }} - {{ file.uploadDate | date('c') }}
{% endfor %}

<!-- Color filters -->
{{ cms.color('id') | color }}  {# CSS-ready color value #}
{{ cms.color('id') | oklch }}
{{ cms.color('id') | rgb }}
{{ cms.color('id') | hsl }}
{{ cms.color('id') | hex }}
```

---

## CLI Automation Scripts

The `TotalCMS` class is equally powerful for writing standalone PHP scripts that run from the command line. Use it to build cron jobs, data processing scripts, bulk operations, and other automation tasks.

### Getting Started

When running from CLI, set `DOCUMENT_ROOT` so Total CMS can locate your data directory, then require the autoloader:

```php
<?php

use Monolog\Level;
use TotalCMS\TotalCMS;

// Set DOCUMENT_ROOT for CLI mode
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/../public';

require_once __DIR__ . '/../public/vendor/autoload.php';
$totalcms = new TotalCMS();
```

In CLI mode, the constructor automatically skips session and buffer initialization since they are not needed.

### Logging

Create a named logger for your script. Logs appear in the Log Analyzer in the admin dashboard.

```php
$logger = $totalcms->createLogger(
    name: 'my-script',
    console: true,       // Also output to stdout
    level: Level::Debug  // Log level (null uses system default)
);

$logger->info("Script started");
$logger->debug("Processing item...");
$logger->error("Something went wrong");
```

### Cache Control

```php
// Clear all caches before processing (ensures fresh data)
$totalcms->clearCache();

// Or disable cache reads for the entire process
// (writes still occur to warm shared caches with fresh data)
$totalcms->disableCache();
```

### Reading Data

#### Fetching Collection Indexes

Use `indexReader()` to get a collection's index, which contains lightweight references to all objects.

```php
$indexReader = $totalcms->indexReader();
$index = $indexReader->fetchIndex('products');

$count = $index->objects->count();
$logger->info("Found {$count} products");

// Iterate over index entries
$index->objects->each(function ($item) {
    $id = $item["id"];
    // Process each item...
});
```

#### Fetching Full Objects

Use `objectFetcher()` to retrieve complete objects with all their data.

```php
$objectFetcher = $totalcms->objectFetcher();
$product = $objectFetcher->fetchObject('products', 'widget-pro');
$data = $product->toArray();

echo $data['title'];    // "Widget Pro"
echo $data['price'];    // 29.99
```

#### Searching Indexes

```php
$indexSearcher = $totalcms->indexSearcher();
// Search within a collection's index
```

#### Fetching Properties

```php
$propertyFetcher = $totalcms->propertyFetcher();
// Retrieve specific properties from objects
```

### Writing Data

#### Creating Objects

```php
$objectSaver = $totalcms->objectSaver();
$objectSaver->saveObject('blog', [
    'id'    => 'my-new-post',
    'title' => 'Hello World',
    'body'  => 'This is my first post.',
]);
```

#### Updating Objects

```php
$objectUpdater = $totalcms->objectUpdater();
$objectUpdater->updateObject('blog', 'my-new-post', [
    'title' => 'Updated Title',
]);
```

#### Removing Objects

```php
$objectRemover = $totalcms->objectRemover();
$objectRemover->removeObject('blog', 'my-new-post');
```

#### Cloning Objects

```php
$objectCloner = $totalcms->objectCloner();
$objectCloner->cloneObject('blog', 'my-post', 'my-post-copy');
```

#### Incrementing Properties

```php
$incrementer = $totalcms->propertyIncrementer();
$incrementer->incrementProperty('products', 'widget-pro', 'stock', 10);
$incrementer->decrementProperty('products', 'widget-pro', 'stock', 1);
```

### Deck Items

Deck properties are key-value maps stored within an object (e.g., line items, tags, entries). Use the deck services to manage individual items without rewriting the entire object.

```php
// Add a new deck item
$totalcms->deckItemSaver()->saveDeckItem(
    collection:   'orders',
    objectId:     'order-123',
    propertyName: 'line_items',
    itemId:       'item_001',
    itemData:     [
        'product'  => 'widget-pro',
        'quantity' => 2,
        'price'    => 29.99,
    ]
);

// Update an existing deck item
$totalcms->deckItemUpdater()->updateDeckItem('orders', 'order-123', 'line_items', 'item_001', [
    'quantity' => 3,
]);

// Fetch a specific deck item
$item = $totalcms->deckItemFetcher()->fetchDeckItem('orders', 'order-123', 'line_items', 'item_001');

// Remove a deck item
$totalcms->deckItemRemover()->removeDeckItem('orders', 'order-123', 'line_items', 'item_001');
```

### Schemas and Collections

```php
// List all available schemas
$schemas = $totalcms->schemaLister()->listSchemas();

// Fetch a specific schema definition
$schema = $totalcms->schemaFetcher()->fetchSchema('blog');

// List all collections
$collections = $totalcms->collectionLister();

// Fetch collection metadata
$collection = $totalcms->collectionFetcher();

// Rebuild a collection's index
$totalcms->indexBuilder()->rebuildIndex('blog');
```

### Email

Send emails using configured mailer templates.

```php
$totalcms->mailer()->sendEmail('order-confirmation', [
    'orderId' => 'order-123',
    'total'   => '$59.98',
]);
```

### Job Queue

Run background jobs programmatically.

```php
$jobRunner = $totalcms->jobRunner();
```

### Files and Images

```php
// Save files and images programmatically
$totalcms->fileSaver()->saveFile('documents', 'doc-id', 'file', $uploadedFile);
$totalcms->imageSaver()->saveImage('gallery', 'gallery-id', 'image', $uploadedFile);

// Get filesystem paths
$path = $totalcms->filePath('my-document');
$depotFile = $totalcms->depotPath('my-depot', 'reports/annual.pdf');
```

### Error Handling with Email Notifications

Wrap your script logic in a try/catch and use the mailer to send error notifications.

```php
try {
    // ... your script logic ...
} catch (Throwable $e) {
    $logger->error("An error occurred: " . $e->getMessage());
    $totalcms->mailer()->sendEmail('script-errors', [
        'job'   => 'my-script',
        'error' => $e->getMessage(),
    ]);
    exit(1);
}

exit(0);
```

### Complete Example: Nightly Data Processing Script

This example shows a typical automation pattern — iterating over a collection, reading related data, performing calculations, and writing results back.

```php
<?php

/**
 * Process Monthly Subscriptions
 *
 * Creates billing records for active subscribers.
 *
 * Usage:
 *   php processSubscriptions.php              # Process for today
 *   php processSubscriptions.php 2026-01-15   # Process for specific date
 */

use Monolog\Level;
use TotalCMS\TotalCMS;

$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/../public';

require_once __DIR__ . '/../public/vendor/autoload.php';
$totalcms = new TotalCMS();
$totalcms->clearCache();

$logger = $totalcms->createLogger(name: 'processSubscriptions', console: true, level: Level::Debug);

// Parse optional date argument
$targetDate = $argv[1] ?? date('Y-m-d');
$logger->info("Processing subscriptions for {$targetDate}");

try {
    $indexReader   = $totalcms->indexReader();
    $objectFetcher = $totalcms->objectFetcher();
    $deckItemSaver = $totalcms->deckItemSaver();

    $created = 0;
    $skipped = 0;

    // Fetch all members
    $membersIndex = $indexReader->fetchIndex('members');
    $logger->info("Found {$membersIndex->objects->count()} members");

    $membersIndex->objects->each(function ($memberItem) use (
        $objectFetcher, $deckItemSaver, $logger, $targetDate, &$created, &$skipped
    ) {
        $memberId = $memberItem["id"];

        try {
            $member = $objectFetcher->fetchObject('members', $memberId)->toArray();
        } catch (Throwable $e) {
            $logger->error("Failed to fetch member {$memberId}: " . $e->getMessage());
            return; // Skip this member
        }

        // Check if member has an active subscription
        $plan = $member["plan"] ?? null;
        if ($plan === null || ($member["status"] ?? '') !== 'active') {
            $skipped++;
            return;
        }

        // Generate a deterministic ID to prevent duplicates
        $billingId = $memberId . "_" . str_replace('-', '_', substr($targetDate, 0, 7));

        // Check if already billed this period
        $billings = $member["billings"] ?? [];
        if (isset($billings[$billingId])) {
            $skipped++;
            return;
        }

        // Create billing record
        $deckItemSaver->saveDeckItem(
            collection:   'members',
            objectId:     $memberId,
            propertyName: 'billings',
            itemId:       $billingId,
            itemData:     [
                "amount"  => $member["plan_price"] ?? 0,
                "created" => $targetDate,
                "plan"    => $plan,
                "status"  => "pending",
            ]
        );
        $created++;
        $logger->info("Created billing {$billingId} for {$memberId}");
    });

    $logger->info("Complete. Created: {$created}, Skipped: {$skipped}");

} catch (Throwable $e) {
    $logger->error("An error occurred: " . $e->getMessage());
    $totalcms->mailer()->sendEmail('script-errors', [
        'job'   => 'processSubscriptions',
        'error' => $e->getMessage(),
    ]);
    exit(1);
}

exit(0);
```

---

## Page Access Control

These methods are available for web pages (not CLI scripts).

```php
// Restrict page to logged-in users (redirects to login if not authenticated)
$totalcms->restrictPageAccess();

// Restrict to specific groups
$totalcms->restrictPageAccess(['admin', 'editor']);

// Check if user is logged in
if ($totalcms->isUserLoggedIn()) {
    $user = $totalcms->userData();
    echo "Welcome, " . $user['name'];
}

// Disable browser caching for authenticated users
$totalcms->noCacheIfAuthenticated();
```

## Sitemaps

Generate XML sitemaps for collections.

```php
// Get sitemap XML as a string
$xml = $totalcms->sitemapForCollection('blog', ['baseUrl' => 'https://example.com']);

// Or create a PSR-7 response
$response = $totalcms->createSitemapResponse('blog', ['baseUrl' => 'https://example.com']);
```

## Method Reference

### Constructor

| Method | Description |
|--------|-------------|
| `new TotalCMS(bool $autoStartBuffer = true)` | Initialize Total CMS. In CLI mode, session and buffer are skipped automatically. |

### Service Accessors

| Method | Returns | Description |
|--------|---------|-------------|
| `collectionLister()` | `CollectionLister` | List available collections |
| `collectionFetcher()` | `CollectionFetcher` | Fetch collection metadata |
| `indexReader()` | `IndexReader` | Read collection indexes |
| `indexSearcher()` | `IndexSearcher` | Search within indexes |
| `indexBuilder()` | `IndexBuilder` | Rebuild collection indexes |
| `objectFetcher()` | `ObjectFetcher` | Fetch full objects |
| `objectSaver()` | `ObjectSaver` | Create new objects |
| `objectUpdater()` | `ObjectUpdater` | Update existing objects |
| `objectRemover()` | `ObjectRemover` | Delete objects |
| `objectCloner()` | `ObjectCloner` | Clone/duplicate objects |
| `propertyFetcher()` | `PropertyFetcher` | Fetch object properties |
| `propertyIncrementer()` | `ObjectPropertyIncrementer` | Increment/decrement numeric properties |
| `deckItemSaver()` | `DeckItemSaver` | Add items to deck properties |
| `deckItemUpdater()` | `DeckItemUpdater` | Update deck items |
| `deckItemRemover()` | `DeckItemRemover` | Remove deck items |
| `deckItemFetcher()` | `DeckItemFetcher` | Fetch specific deck items |
| `schemaFetcher()` | `SchemaFetcher` | Fetch schema definitions |
| `schemaLister()` | `SchemaLister` | List available schemas |
| `fileSaver()` | `FileSaver` | Save files programmatically |
| `imageSaver()` | `ImageSaver` | Save images programmatically |
| `mailer()` | `EmailService` | Send emails via templates |
| `jobRunner()` | `JobRunner` | Run background jobs |

### Logging

| Method | Returns | Description |
|--------|---------|-------------|
| `createLogger(string $name, bool $console = false, ?Level $level = null)` | `LoggerInterface` | Create a named logger for custom scripts |

### Cache Control

| Method | Returns | Description |
|--------|---------|-------------|
| `clearCache()` | `array` | Clear all caches |
| `disableCache()` | `void` | Disable cache reads for current process |

### Page Access

| Method | Returns | Description |
|--------|---------|-------------|
| `restrictPageAccess(array\|string $groups = [], string $collection = '')` | `void` | Restrict page to authenticated users/groups |
| `isUserLoggedIn(string $collection = '')` | `bool` | Check if a user is logged in |
| `userData()` | `array` | Get the logged-in user's data |
| `noCacheIfAuthenticated(string $collection = '')` | `void` | Disable browser caching for logged-in users |

### Buffer and Rendering

| Method | Returns | Description |
|--------|---------|-------------|
| `startBuffer()` | `void` | Start output buffering |
| `endBuffer()` | `void` | End output buffering |
| `processBufferMacros(array $data = [], bool $restartBuffer = false)` | `string` | Process buffered content through Twig |
| `processMacros(string $templateName, array $data = [])` | `string` | Render a named Twig template |

### Sitemaps

| Method | Returns | Description |
|--------|---------|-------------|
| `sitemapForCollection(string $collection, array $options = [])` | `string` | Generate sitemap XML |
| `createSitemapResponse(string $collection, array $options = [])` | `ResponseInterface` | Create a PSR-7 sitemap response |

### File Paths

| Method | Returns | Description |
|--------|---------|-------------|
| `filePath(string $id, array $options = [])` | `?string` | Get filesystem path for a file property |
| `depotPath(string $id, string $filePath, array $options = [])` | `?string` | Get filesystem path for a depot file |

### Static Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `TotalCMS::isPreview()` | `bool` | Check if running in preview mode |

### Public Properties

| Property | Type | Description |
|----------|------|-------------|
| `config` | `Config` | Access to the Total CMS configuration object |
