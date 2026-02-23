# Data Views Feature - Implementation Plan

**Status**: Ready for Implementation
**Target Version**: 3.2
**Last Updated**: 2026-02-22

## Executive Summary

Data Views is a feature that allows users to create custom, pre-computed data structures from one or more collections
using Twig templates. Similar to materialized views in traditional databases, Data Views solve the performance problem
of complex data aggregations and cross-collection queries by computing results once and caching them for reuse.

### Problem Statement

Currently, complex layouts that pull data from multiple collections, perform aggregations, or require nested lookups
must recompute this data on every page load. For large datasets or complex operations, this can significantly impact
performance.

### Solution

Data Views allows users to:
1. Write Twig templates that query and transform collection data
2. Store the resulting data structure as JSON
3. Automatically update when dependent collections change (via job queue)
4. Access the pre-computed data instantly via `cms.view('name')` in templates

### Use Cases

- **Complex Aggregations**: "All blog posts with author names, category names, and comment counts"
- **Cross-Collection Reporting**: "Total posts by category across all collections"
- **Denormalized Data**: Flatten normalized data structures for frontend performance
- **Dashboard Stats**: Pre-computed metrics and recent items for admin dashboards
- **Performance Optimization**: Any expensive query that doesn't need real-time updates

## Key Design Decisions

### Architecture Decisions

1. **Storage Format**: JSON files in `tcms-data/.system/dataviews/{viewId}/`
   - Consistent with existing flat-file approach
   - Each view has: `meta.json`, `definition.twig`, `data.json`

2. **Update Strategy**: Job queue based
   - Views update asynchronously when dependent collections change
   - Prevents timeout issues on large datasets
   - Job deduplication prevents queue flooding

3. **Caching Strategy**: Multi-backend (like indexes)
   - APCu -> Redis -> Memcached -> Filesystem
   - Uses `CacheManager::storeComputedData()` / `getComputedData()` / `clearComputedData()`
   - TTL: 1800s (30 minutes, same as `TTL_API_RESPONSE`)
   - Cache cleared on view rebuild

4. **Permissions Model**: Simple boolean (like Playground)
   - `dataviews` permission key in `AccessGroupData::getDefaultPermissions()`
   - All-or-nothing access (no per-view permissions)
   - Default: `false` (opt-in, admin-only by default)

5. **Output Capture**: Line-based JSON extraction
   - Render Twig template using `$twig->createTemplate($definition)->render($context)`
   - Extract JSON from last non-empty line of output
   - Validate JSON structure
   - Allows user debugging output during development

### Technical Decisions

1. **Dependency Management**: Manual (user-specified)
   - Users list dependent collections in view metadata
   - Automatic detection too complex for edge cases
   - Simple list field in UI

2. **Timeout Handling**: Fallback to job queue
   - Try immediate build on manual rebuild
   - If timeout, queue job and return 202 Accepted
   - User can monitor via job queue manager

3. **Error Handling**: Graceful degradation
   - Keep old data on build failure
   - Store error message in `meta.json`
   - Log to system logs
   - Display errors in admin UI

4. **Stale Data**: Accept temporary staleness
   - View-in-view dependencies may have stale data during rebuilds
   - Documented limitation
   - Future: Implement dependency ordering if needed

5. **View Naming**: Auto-slugified
   - User enters display name
   - System generates URL-safe slug as ID
   - Consistent with existing patterns

### User Interface Decisions

1. **Navigation Placement**: Top-level sidebar menu
   - Between Collections and Templates
   - Icon class: `.dataviews`
   - Route: `/admin/dataviews`

2. **Test Mode**: Show all output
   - Display JSON result
   - Also show any debug output
   - Helps users debug their Twig

3. **Initial Build**: Automatic queue on save
   - First build queued when view created
   - Prevents timeout on large initial builds
   - User can see progress in job queue

## Detailed Implementation Plan

### Phase 1: Core Infrastructure

#### 1.1 Data Structures
**File**: `src/Domain/DataView/Data/DataViewData.php`

```php
readonly class DataViewData
{
    public function __construct(
        public string $id,              // Slugified view identifier
        public string $name,            // Display name
        public string $description,     // User description
        public array $dependencies,     // Collection IDs this view uses
        public string $createdAt,       // ISO 8601 timestamp
        public string $updatedAt,       // ISO 8601 timestamp
        public ?string $lastBuilt,      // Last successful build timestamp
        public ?string $lastError,      // Last error message (null if success)
    ) {}
}
```

**Storage Structure**:
```
tcms-data/.system/dataviews/
├── blog-summary/
│   ├── meta.json           # DataViewData as JSON
│   ├── definition.twig     # User's Twig template
│   └── data.json          # Computed view data
└── homepage-stats/
    ├── meta.json
    ├── definition.twig
    └── data.json
```

#### 1.2 Repository Layer
**File**: `src/Domain/DataView/Repository/DataViewRepository.php`

**Methods**:
- `exists(string $viewId): bool`
- `fetch(string $viewId): DataViewData`
- `fetchAll(): array<DataViewData>`
- `save(DataViewData $view): void`
- `delete(string $viewId): void`
- `saveDefinition(string $viewId, string $twig): void`
- `fetchDefinition(string $viewId): string`
- `saveData(string $viewId, array $data): void`
- `fetchData(string $viewId): array`
- `findByDependency(string $collection): array<DataViewData>`

**Implementation Notes**:
- Use existing file utilities for reading/writing JSON
- Validate view ID format (slug)
- Handle missing files gracefully
- Base path: `$config->datadir . '/.system/dataviews'`

#### 1.3 Job Queue Integration

**Update**: `src/Domain/JobQueue/Data/JobData.php`
```php
// Add new job type constant
public const TYPE_VIEW_UPDATE = 'view_update';

// Add to TYPE_LIST array
public const TYPE_LIST = [
    self::TYPE_IMPORT,
    self::TYPE_EXPORT,
    self::TYPE_REBUILD,
    self::TYPE_UPDATE,
    self::TYPE_FACTORY,
    self::TYPE_VIEW_UPDATE,  // <-- Add this
];
```

**Update**: `src/Domain/JobQueue/Service/JobQueuer.php`
```php
public function queueViewUpdate(string $viewId): void
{
    // Check for existing queued job (deduplication)
    // Use existing queueByType() or similar to check for pending view_update jobs
    // with matching viewId in payload

    $this->queueJob(JobData::TYPE_VIEW_UPDATE, 'dataviews', [
        'viewId' => $viewId,
    ]);
}
```

**Update**: `src/Domain/JobQueue/Service/JobRunner.php`
```php
// In processJob() switch statement, add:
case JobData::TYPE_VIEW_UPDATE:
    $payload = json_decode($job->payload, true) ?? [];
    $viewId = $payload['viewId'] ?? '';

    // Safety: Skip if view was deleted (job is deleted on success)
    if ($viewId === '' || !$this->viewRepository->exists($viewId)) {
        return;
    }

    $this->viewBuilder->buildView($viewId);
    break;
```

**Note**: Successful jobs are **deleted** from the SQLite database (not transitioned to "completed"). Only failed jobs persist. This is the existing pattern — no changes needed.

#### 1.4 Permissions System

**Update**: `src/Domain/AccessGroup/Data/AccessGroupData.php`
```php
// In getDefaultPermissions(), add after 'docs':
'dataviews' => false,
```

**Update**: `src/Domain/Auth/Service/AccessControlService.php`
```php
public function canAccessDataViews(string $userId): bool
{
    if ($this->userValidation->isSuperAdmin($userId)) {
        return true;
    }

    $groups = $this->getUserAccessGroups($userId);
    if ($groups === []) {
        return false;
    }

    foreach ($groups as $group) {
        if ($this->groupCanAccessDataViews($group)) {
            return true;
        }
    }

    return false;
}

private function groupCanAccessDataViews(AccessGroupData $group): bool
{
    return $group->permissions['dataviews'] ?? false;
}
```

**Create**: `src/Middleware/Access/DataViewsAccessMiddleware.php`
```php
<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;

readonly class DataViewsAccessMiddleware extends BaseAccessMiddleware
{
    protected const RESOURCE_NAME = 'dataviews';

    protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
    {
        return $this->accessControl->canAccessDataViews($userId);
    }
}
```

**Update**: `src/Domain/Twig/Adapter/TotalCMSTwigAdapter.php`
```php
public function canAccessDataViews(): bool
{
    if ($this->config->auth['enable'] === false) {
        return true;
    }

    $userData = $this->accessManager->userData();
    if ($userData === [] || !isset($userData['id'])) {
        return false;
    }

    return $this->accessControl->canAccessDataViews($userData['id']);
}
```

### Phase 2: View Building & Management

#### 2.1 View Builder Service
**File**: `src/Domain/DataView/Service/ViewBuilder.php`

**Main Method**:
```php
public function buildView(string $viewId): void
{
    try {
        // Fetch view metadata and definition
        $view = $this->viewRepository->fetch($viewId);
        $definition = $this->viewRepository->fetchDefinition($viewId);

        // Execute Twig and capture output
        $jsonData = $this->executeTwigTemplate($definition);

        // Validate JSON structure
        if ($jsonData === null) {
            throw new RuntimeException('View did not produce valid JSON output');
        }

        // Save computed data
        $this->viewRepository->saveData($viewId, $jsonData);

        // Update metadata (clear error, set lastBuilt)
        $this->updateSuccessMetadata($viewId);

        // Clear view cache
        $this->cacheManager->clearComputedData(self::CACHE_PREFIX . $viewId);

    } catch (Exception $e) {
        // Log error
        $this->logger->error("View build failed: {$viewId}", [
            'error' => $e->getMessage()
        ]);

        // Store error in metadata (keep old data)
        $this->updateErrorMetadata($viewId, $e->getMessage());
    }
}

private function executeTwigTemplate(string $definition): ?array
{
    // Use createTemplate() for inline Twig strings (NOT file-based rendering)
    $template = $this->twig->createTemplate($definition);

    // Render with full CMS context (same as Playground)
    $output = $template->render([
        'cms' => $this->twigAdapter,
    ]);

    // Extract JSON from last non-empty line
    $lines = array_filter(explode("\n", $output), fn($line) => trim($line) !== '');
    if ($lines === []) {
        return null;
    }

    $jsonLine = trim(end($lines));

    // Decode and validate
    $decoded = json_decode($jsonLine, true);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}
```

#### 2.2 View Manager Service
**File**: `src/Domain/DataView/Service/ViewManager.php`

**Methods**:
```php
public function __construct(
    private DataViewRepository $viewRepository,
    private JobQueuer $jobQueuer,
    private CacheManager $cacheManager,
) {}

private const CACHE_PREFIX = 'dataview:';
private const CACHE_TTL = 1800; // 30 minutes

public function createView(array $input): DataViewData
{
    $this->validateInput($input);

    $viewId = $this->slugify($input['name']);

    if ($this->viewRepository->exists($viewId)) {
        throw new RuntimeException("View with ID '{$viewId}' already exists");
    }

    $view = new DataViewData(
        id: $viewId,
        name: $input['name'],
        description: $input['description'] ?? '',
        dependencies: $input['dependencies'] ?? [],
        createdAt: date('c'),
        updatedAt: date('c'),
        lastBuilt: null,
        lastError: null
    );

    $this->viewRepository->save($view);
    $this->viewRepository->saveDefinition($viewId, $input['definition']);

    // Queue initial build
    $this->jobQueuer->queueViewUpdate($viewId);

    return $view;
}

public function updateView(string $viewId, array $input): DataViewData
{
    $view = $this->viewRepository->fetch($viewId);

    $updated = new DataViewData(
        id: $view->id,
        name: $input['name'] ?? $view->name,
        description: $input['description'] ?? $view->description,
        dependencies: $input['dependencies'] ?? $view->dependencies,
        createdAt: $view->createdAt,
        updatedAt: date('c'),
        lastBuilt: $view->lastBuilt,
        lastError: $view->lastError
    );

    $this->viewRepository->save($updated);

    if (isset($input['definition'])) {
        $this->viewRepository->saveDefinition($viewId, $input['definition']);
    }

    // Queue rebuild
    $this->jobQueuer->queueViewUpdate($viewId);

    return $updated;
}

public function deleteView(string $viewId): void
{
    $this->viewRepository->delete($viewId);
    $this->cacheManager->clearComputedData(self::CACHE_PREFIX . $viewId);
    // Note: Pending jobs will skip deleted views (checked in JobRunner)
}

public function getViewData(string $viewId): array
{
    // Try cache first
    $cached = $this->cacheManager->getComputedData(self::CACHE_PREFIX . $viewId);
    if (is_array($cached)) {
        return $cached;
    }

    // Load from disk
    $data = $this->viewRepository->fetchData($viewId);

    // Cache for next time
    $this->cacheManager->storeComputedData(self::CACHE_PREFIX . $viewId, $data, self::CACHE_TTL);

    return $data;
}

public function listViews(): array
{
    return $this->viewRepository->fetchAll();
}

private function slugify(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = (string) preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = (string) preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}
```

#### 2.3 View Update Scheduler
**File**: `src/Domain/DataView/Service/ViewUpdateScheduler.php`

```php
readonly class ViewUpdateScheduler
{
    public function __construct(
        private DataViewRepository $viewRepository,
        private JobQueuer $jobQueuer,
    ) {}

    public function scheduleUpdatesForCollection(string $collection): void
    {
        $dependentViews = $this->viewRepository->findByDependency($collection);

        foreach ($dependentViews as $view) {
            $this->jobQueuer->queueViewUpdate($view->id);
        }
    }
}
```

### Phase 3: Collection Integration

Hook into object lifecycle to trigger view rebuilds when collections change.

**Update**: `src/Domain/Object/Service/ObjectSaver.php`
```php
// Add to constructor:
private ViewUpdateScheduler $viewUpdateScheduler,

// After: $this->indexBuilder->smartBuildIndex($collection, $object);
$this->viewUpdateScheduler->scheduleUpdatesForCollection($collection);
```

**Update**: `src/Domain/Object/Service/ObjectUpdater.php`
```php
// Add to constructor:
private ViewUpdateScheduler $viewUpdateScheduler,

// After: $this->indexBuilder->smartBuildIndex($collection, $object);
$this->viewUpdateScheduler->scheduleUpdatesForCollection($collection);
```

**Update**: `src/Domain/Object/Service/ObjectRemover.php`
```php
// Add to constructor:
private ViewUpdateScheduler $viewUpdateScheduler,

// After smartBuildIndex call:
$this->viewUpdateScheduler->scheduleUpdatesForCollection($collection);
```

**Update**: `config/container.php` — update ObjectSaver, ObjectUpdater, ObjectRemover registrations to inject `ViewUpdateScheduler`.

### Phase 4: HTTP API & Actions

#### 4.1 API Action Files

**File**: `src/Action/DataView/DataViewListAction.php`
- **Route**: GET `/dataviews`
- **Returns**: Array of all DataViewData (metadata only, no computed data)

**File**: `src/Action/DataView/DataViewFetchAction.php`
- **Route**: GET `/dataviews/{id}`
- **Returns**: View computed data (the JSON result)

**File**: `src/Action/DataView/DataViewSaveAction.php`
- **Route**: POST `/dataviews`
- **Body**: `{name, description, dependencies, definition}`
- **Returns**: Created DataViewData
- **Side Effect**: Queues initial build job

**File**: `src/Action/DataView/DataViewUpdateAction.php`
- **Route**: PUT `/dataviews/{id}`
- **Body**: Same as save (partial updates allowed)
- **Returns**: Updated DataViewData
- **Side Effect**: Queues rebuild job

**File**: `src/Action/DataView/DataViewDeleteAction.php`
- **Route**: DELETE `/dataviews/{id}`
- **Returns**: Success message

**File**: `src/Action/DataView/DataViewRebuildAction.php`
- **Route**: POST `/dataviews/{id}/rebuild`
- **Logic**: Try immediate build; if timeout, queue job and return 202 Accepted

**File**: `src/Action/DataView/DataViewTestAction.php`
- **Route**: POST `/dataviews/test`
- **Body**: `{definition, dependencies}` (no need to save)
- **Returns**: `{success, output, debugOutput}`

#### 4.2 Admin Action

**File**: `src/Action/Admin/AdminDataViewsAction.php`
- **Route**: GET `/admin/dataviews[/{id}]`
- **Returns**: Rendered admin UI (Twig template)

#### 4.3 Routes Configuration

**File**: `config/routes/dataviews.php`
```php
<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\DataView;
use TotalCMS\Middleware\Access\DataViewsAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;

return function (App $app): void {
    $app->group('/dataviews', function (RouteCollectorProxy $group): void {
        $group->get('', DataView\DataViewListAction::class)->setName('dataview-list');
        $group->post('', DataView\DataViewSaveAction::class)->setName('dataview-save');
        $group->post('/test', DataView\DataViewTestAction::class)->setName('dataview-test');
        $group->get('/{id}', DataView\DataViewFetchAction::class)->setName('dataview-fetch');
        $group->put('/{id}', DataView\DataViewUpdateAction::class)->setName('dataview-update');
        $group->delete('/{id}', DataView\DataViewDeleteAction::class)->setName('dataview-delete');
        $group->post('/{id}/rebuild', DataView\DataViewRebuildAction::class)->setName('dataview-rebuild');
    })->add(DataViewsAccessMiddleware::class)
        ->add(AuthMiddleware::class);
};
```

**Update**: `config/routes/admin.php`
```php
// Add inside the /admin group:
$group->get('/dataviews[/{id}]', AdminDataViewsAction::class)
    ->setName('admin-dataviews')
    ->add(DataViewsAccessMiddleware::class);
```

**Update**: `config/routes.php` — Include the new `dataviews.php` routes file.

### Phase 5: Twig Integration

**Update**: `src/Domain/Twig/Extension/TotalCMSTwigExtension.php`

```php
// Add to getFunctions() method
new TwigFunction('view', [$this->adapter, 'view']),
```

**Update**: `src/Domain/Twig/Adapter/TotalCMSTwigAdapter.php`

```php
// Add ViewManager to constructor
private readonly ViewManager $viewManager,

// Add method
public function view(string $viewId): array
{
    try {
        return $this->viewManager->getViewData($viewId);
    } catch (Exception $e) {
        $this->logger->warning("View not found or failed to load: {$viewId}", [
            'error' => $e->getMessage()
        ]);
        return []; // Graceful fallback
    }
}
```

**Update**: `config/container.php` — Add `ViewManager` to `TotalCMSTwigAdapter` registration.

**Usage Example**:
```twig
{# In any template #}
{% set blogStats = cms.view('blog-summary') %}

<h2>Blog Statistics</h2>
<p>Total Posts: {{ blogStats.totalPosts }}</p>
<p>Total Authors: {{ blogStats.totalAuthors }}</p>

{% for category, count in blogStats.postsByCategory %}
    <li>{{ category }}: {{ count }} posts</li>
{% endfor %}
```

### Phase 6: Admin UI

#### 6.1 Navigation Update

**Update**: `resources/templates/admin-dashboard.twig`

Add after Collections menu item, before Templates:
```twig
{% if cms.canAccessDataViews() %}
<li class="menu-item{% if url.page == 'dataviews' %} active{% endif %}">
    <a class="dataviews" href="dataviews" title="Data Views">Data Views</a>
</li>
{% endif %}
```

**CSS**: Add icon styles to `/resources/scss/dashboard.scss`
```scss
.menu-item a.dataviews {
    // Use existing icon pattern (similar to .templates, .playground)
}
```

#### 6.2 Views List Page

**File**: `resources/templates/admin/dataviews/index.twig`

**Structure**:
- Page header with "Create New View" button
- Table with columns:
  - Name
  - Dependencies (comma-separated collection names)
  - Last Updated
  - Status (success or error with hover tooltip)
- Action buttons per row:
  - Edit (pencil icon)
  - Test (play icon)
  - Rebuild (refresh icon)
  - Delete (trash icon)
- Empty state if no views exist

#### 6.3 View Editor

**File**: `resources/templates/admin/dataviews/edit.twig`

**Form Fields**:
1. **Name** (text input) — Auto-slugifies to ID on blur, show computed ID below
2. **Description** (textarea) — Optional, for documentation
3. **Dependencies** (multi-select) — List of all collections, user can select multiple
4. **Twig Definition** (code editor) — Syntax highlighting, full-height editor
5. **Error Display** (if exists) — Show last error in red alert box with timestamp

**Buttons**:
- **Test Run**: Opens modal with JSON output preview + debug output
- **Save**: Creates/updates view and queues build
- **Cancel**: Return to list

#### 6.4 JavaScript

**File**: `javascript/admin/dataviews.js`

**Functionality**:
- Name to ID slugification (live preview)
- Test run AJAX (POST to `/dataviews/test`)
- Form validation (name required, definition required)
- Confirmation on delete
- Rebuild button with loading state

**Build**: Include in ESBuild config

### Phase 7: JumpStart Integration (DEFERRED)

**Deferred to future version.** Views are easily recreated from their definitions, and JumpStart integration adds complexity to the initial implementation. When implemented:

- Export view definitions (Twig + metadata), NOT computed data
- Rebuild views on import via job queue
- Add `dataviews` property to `JumpStartData`
- Update `JumpStartExporter` and `JumpStartImporter`

## Container & Dependency Injection

**Update**: `config/container.php`

Register all new services with explicit closure factories (no auto-wiring):
```php
// Data View Services
DataViewRepository::class => fn (ContainerInterface $container): DataViewRepository => new DataViewRepository(
    $container->get(Config::class),
),
ViewBuilder::class => fn (ContainerInterface $container): ViewBuilder => new ViewBuilder(
    $container->get(DataViewRepository::class),
    $container->get(CacheManager::class),
    $container->get(TwigEngine::class),
    $container->get(TotalCMSTwigAdapter::class),
    $container->get(LoggerFactory::class),
),
ViewManager::class => fn (ContainerInterface $container): ViewManager => new ViewManager(
    $container->get(DataViewRepository::class),
    $container->get(JobQueuer::class),
    $container->get(CacheManager::class),
),
ViewUpdateScheduler::class => fn (ContainerInterface $container): ViewUpdateScheduler => new ViewUpdateScheduler(
    $container->get(DataViewRepository::class),
    $container->get(JobQueuer::class),
),

// DataViews Access Middleware
DataViewsAccessMiddleware::class => fn (ContainerInterface $container): DataViewsAccessMiddleware => new DataViewsAccessMiddleware(
    $container->get(UserValidationService::class),
    $container->get(AccessControlService::class),
    $container->get(PhpSession::class),
    $container->get(JsonRenderer::class),
    $container->get(TwigRenderer::class),
    $container->get(ResponseFactoryInterface::class),
    $container->get(Config::class),
    $container->get(OperationDetector::class),
    $container->get(LoggerFactory::class),
),

// Update ObjectSaver, ObjectUpdater, ObjectRemover to inject ViewUpdateScheduler
// Update TotalCMSTwigAdapter to inject ViewManager
// Update JobRunner to inject ViewBuilder and DataViewRepository
```

## Testing Strategy

### Unit Tests

**File**: `tests/Unit/DataView/ViewBuilderTest.php`
- Test Twig execution and JSON extraction from output
- Test invalid JSON handling
- Test error storage in metadata

**File**: `tests/Unit/DataView/ViewManagerTest.php`
- Test view creation (name slugification)
- Test duplicate ID prevention
- Test view update and deletion
- Test cache integration (get/set/clear)

**File**: `tests/Unit/DataView/ViewUpdateSchedulerTest.php`
- Test finding dependent views
- Test job queuing for multiple views

### Integration Tests

**File**: `tests/Feature/DataViewCRUDTest.php`
- Test full CRUD lifecycle via API
- Test permissions (require `canAccessDataViews`)
- Test API response formats

**File**: `tests/Feature/DataViewBuildTest.php`
- Test view execution with real collections
- Test view data caching
- Test error handling and recovery

**File**: `tests/Feature/DataViewCollectionHooksTest.php`
- Create view depending on collection
- Update collection object
- Verify view update job was queued
- Process job and verify view data updated

**File**: `tests/Feature/DataViewTwigIntegrationTest.php`
- Test `cms.view()` function in templates
- Test missing view handling (returns empty array)

## Future Enhancements

### TotalCMSTwigAdapter Refactor (Separate Project)
The adapter is currently 2600+ lines with 30+ constructor params. A future refactor could:
- Extract domain-specific sub-adapters (e.g., `CacheAdapter`, `CollectionAdapter`, `ViewAdapter`)
- Use a service locator pattern for lazy resolution
- Group related methods into smaller, testable units
This is NOT a blocker for Data Views — adding `view()` and `canAccessDataViews()` is minimal.

### Deferred to Future Versions

1. **JumpStart Integration**: Export/import view definitions (deferred from Phase 7)
2. **Parameterized Views**: `cms.view('posts', {author: 'joe'})` — requires parameter caching strategy
3. **Incremental Updates**: Only update changed data — complex dependency tracking
4. **View-on-View Dependency Resolution**: Topological sort for build order, circular dependency detection
5. **Scheduled Rebuilds**: Cron-based view updates independent of collection changes
6. **View Versioning**: History of view changes with rollback capability
7. **Performance Monitoring**: Dashboard with build times, cache hit rates

## Security Considerations

1. **Twig Sandbox**: No additional sandboxing beyond normal Twig. Users can access same data as Playground. Permission-gated (admin-only by default).
2. **Input Validation**: Validate view IDs (alphanumeric + hyphens only). Sanitize user input in forms. CSRF protection on all actions.
3. **File System**: Store views in `.system` directory (not directly accessible). Use `.htaccess` protection (like other data directories).
4. **API Access**: All routes protected by `DataViewsAccessMiddleware`. Check permissions on every request.

## Success Criteria

Feature is considered complete when:

- Users can create, edit, delete views via admin UI
- Views automatically update when dependent collections change
- View data is cached and performs well (< 10ms average access time)
- Test mode allows debugging Twig before saving
- Permissions properly restrict access
- Jobs handle errors gracefully and don't duplicate
- All tests pass (unit + integration)
- PHPStan Level 8 compliance maintained

## References

**Structural Reference**: Playground feature — closest existing pattern (admin UI + REST API + boolean permission)

**Related Total CMS Features**:
- Collection Indexes (similar caching strategy)
- Playground (similar permissions model, similar admin UI pattern)
- Templates (similar storage pattern)
- Job Queue (async processing pattern)
