# Data Views Feature - Implementation Plan

**Status**: Planning Phase
**Target Version**: TBD (Post 3.1)
**Last Updated**: 2025-01-06

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
   - APCu → Redis → Memcached → Filesystem
   - 30-minute TTL (same as collection indexes)
   - Cache cleared on view rebuild

4. **Permissions Model**: Simple boolean (like Playground)
   - `canAccessDataViews` permission
   - All-or-nothing access (no per-view permissions)
   - Admin-only feature by default

5. **Output Capture**: Line-based JSON extraction (Option 3)
   - Capture all Twig output
   - Extract JSON from last non-empty line
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

6. **JumpStart Integration**: Export definitions, not data
   - Export view definitions (Twig + metadata)
   - Do NOT export computed data
   - Rebuild on import

### User Interface Decisions

1. **Navigation Placement**: Top-level sidebar menu
   - Between Collections and Templates
   - Icon class: `.dataviews`
   - Route: `/admin/dataviews`

2. **Test Mode**: Show all output (Option C)
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

#### 1.3 Job Queue Integration

**Update**: `src/Domain/JobQueue/Data/JobData.php`
```php
// Add new job type constant
public const TYPE_VIEW_UPDATE = 'view_update';
```

**Update**: `src/Domain/JobQueue/Service/JobQueuer.php`
```php
public function queueViewUpdate(string $viewId): void
{
    // Check for existing queued job (deduplication)
    if ($this->jobRepository->hasViewUpdateQueued($viewId)) {
        return;
    }

    $this->queueJob(JobData::TYPE_VIEW_UPDATE, $viewId);
}
```

**Update**: `src/Domain/JobQueue/Repository/JobRepository.php`
```php
public function hasViewUpdateQueued(string $viewId): bool
{
    // Query for pending view_update jobs with matching viewId
    // Return true if any exist
}
```

**Update**: `src/Domain/JobQueue/Service/JobRunner.php`
```php
// In processJob() switch statement, add:
case JobData::TYPE_VIEW_UPDATE:
    $viewId = $job->data['viewId'] ?? '';

    // Safety: Skip if view was deleted
    if (!$this->viewRepository->exists($viewId)) {
        return;
    }

    $this->viewBuilder->buildView($viewId);
    break;
```

#### 1.4 Permissions System

**Update**: `src/Domain/Auth/Data/AccessGroupData.php`
```php
private function getDefaultPermissions(): array
{
    return [
        // ... existing permissions
        'dataviews' => false,  // Add this
    ];
}
```

**Update**: `src/Domain/Auth/Service/AccessControlService.php`
```php
public function canAccessDataViews(string $userId): bool
{
    if ($this->userValidation->isSuperAdmin($userId)) {
        return true;
    }

    $groups = $this->getUserAccessGroups($userId);
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
readonly class DataViewsAccessMiddleware extends BaseAccessMiddleware
{
    protected const RESOURCE_NAME = 'dataviews';

    protected function checkPermission(
        string $userId,
        string $operation,
        ServerRequestInterface $request
    ): bool {
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

    $userData = $this->getUserData();
    if (!$userData) {
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

        // Execute Twig with output buffering
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
        $this->viewCacheManager->clear($viewId);

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
    // Create sandbox with viewData variable
    $sandboxTemplate = <<<'TWIG'
{% set viewData = {} %}
{{ include(templatePath) }}
{{ viewData | json_encode }}
TWIG;

    // Capture output
    ob_start();
    $this->twig->render($sandboxTemplate, [
        'templatePath' => $definition,
        // Full CMS context available (same as Playground)
    ]);
    $output = ob_get_clean();

    // Extract JSON from last line (Option 3)
    $lines = array_filter(explode("\n", $output), fn($line) => trim($line) !== '');
    $jsonLine = trim(end($lines));

    // Decode and validate
    return json_decode($jsonLine, true);
}
```

#### 2.2 View Cache Manager
**File**: `src/Domain/DataView/Service/ViewCacheManager.php`

```php
readonly class ViewCacheManager
{
    private const CACHE_PREFIX = 'dataview:';
    private const TTL = 1800; // 30 minutes (same as indexes)

    public function __construct(
        private CacheManager $cacheManager
    ) {}

    public function get(string $viewId): ?array
    {
        return $this->cacheManager->getComputedData(
            self::CACHE_PREFIX . $viewId
        );
    }

    public function set(string $viewId, array $data): void
    {
        $this->cacheManager->setComputedData(
            self::CACHE_PREFIX . $viewId,
            $data,
            self::TTL
        );
    }

    public function clear(string $viewId): void
    {
        $this->cacheManager->clearComputedData(
            self::CACHE_PREFIX . $viewId
        );
    }
}
```

#### 2.3 View Manager Service
**File**: `src/Domain/DataView/Service/ViewManager.php`

**Methods**:
```php
public function createView(array $input): DataViewData
{
    // Validate input
    $this->validateInput($input);

    // Generate slugified ID from name
    $viewId = $this->slugify($input['name']);

    // Check if ID already exists
    if ($this->viewRepository->exists($viewId)) {
        throw new RuntimeException("View with ID '{$viewId}' already exists");
    }

    // Create view data
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

    // Save metadata and definition
    $this->viewRepository->save($view);
    $this->viewRepository->saveDefinition($viewId, $input['definition']);

    // Queue initial build
    $this->jobQueuer->queueViewUpdate($viewId);

    return $view;
}

public function updateView(string $viewId, array $input): void
{
    // Fetch existing view
    $view = $this->viewRepository->fetch($viewId);

    // Update fields
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

    // Save
    $this->viewRepository->save($updated);

    if (isset($input['definition'])) {
        $this->viewRepository->saveDefinition($viewId, $input['definition']);
    }

    // Queue rebuild
    $this->jobQueuer->queueViewUpdate($viewId);
}

public function deleteView(string $viewId): void
{
    $this->viewRepository->delete($viewId);
    $this->viewCacheManager->clear($viewId);
    // Note: Pending jobs will skip deleted views
}

public function getViewData(string $viewId): array
{
    // Try cache first
    $cached = $this->viewCacheManager->get($viewId);
    if ($cached !== null) {
        return $cached;
    }

    // Load from disk
    $data = $this->viewRepository->fetchData($viewId);

    // Cache for next time
    $this->viewCacheManager->set($viewId, $data);

    return $data;
}

public function listViews(): array
{
    return $this->viewRepository->fetchAll();
}

private function slugify(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}
```

#### 2.4 View Update Scheduler
**File**: `src/Domain/DataView/Service/ViewUpdateScheduler.php`

```php
readonly class ViewUpdateScheduler
{
    public function __construct(
        private DataViewRepository $viewRepository,
        private JobQueuer $jobQueuer
    ) {}

    public function scheduleUpdatesForCollection(string $collection): void
    {
        // Find all views that depend on this collection
        $dependentViews = $this->viewRepository->findByDependency($collection);

        // Queue update for each (with deduplication in jobQueuer)
        foreach ($dependentViews as $view) {
            $this->jobQueuer->queueViewUpdate($view->id);
        }
    }
}
```

### Phase 3: Collection Integration

**Update**: `src/Domain/Object/Service/ObjectSaver.php`
```php
// After line 44: $this->indexBuilder->smartBuildIndex($collection, $object);
$this->viewUpdateScheduler->scheduleUpdatesForCollection($collection);
```

**Update**: `src/Domain/Object/Service/ObjectUpdater.php`
```php
// After line 46: $this->indexBuilder->smartBuildIndex($collection, $object);
$this->viewUpdateScheduler->scheduleUpdatesForCollection($collection);
```

**Update**: `src/Domain/Object/Service/ObjectRemover.php`
```php
// After smartBuildIndex call
$this->viewUpdateScheduler->scheduleUpdatesForCollection($collection);
```

**Dependency Injection**: Update `config/container.php` to wire ViewUpdateScheduler into these services.

### Phase 4: HTTP API & Actions

#### 4.1 API Action Files

**File**: `src/Action/DataView/DataViewListAction.php`
- **Route**: GET `/api/dataviews`
- **Returns**: Array of all DataViewData (metadata only, no data)
- **Response**: JSON array

**File**: `src/Action/DataView/DataViewFetchAction.php`
- **Route**: GET `/api/dataviews/{id}`
- **Returns**: View data only (not meta) for consistency with other APIs
- **Response**: JSON object (the computed view data)

**File**: `src/Action/DataView/DataViewSaveAction.php`
- **Route**: POST `/api/dataviews`
- **Body**: `{name, description, dependencies, definition}`
- **Returns**: Created DataViewData
- **Side Effect**: Queues initial build job

**File**: `src/Action/DataView/DataViewUpdateAction.php`
- **Route**: PATCH `/api/dataviews/{id}`
- **Body**: Same as save (partial updates allowed)
- **Returns**: Updated DataViewData
- **Side Effect**: Queues rebuild job

**File**: `src/Action/DataView/DataViewDeleteAction.php`
- **Route**: DELETE `/api/dataviews/{id}`
- **Returns**: Success message
- **Side Effect**: Clears cache (jobs handle themselves)

**File**: `src/Action/DataView/DataViewRebuildAction.php`
- **Route**: POST `/api/dataviews/{id}/rebuild`
- **Logic**:
  ```php
  try {
      // Try immediate build (with PHP max_execution_time)
      $this->viewBuilder->buildView($viewId);
      return new JsonResponse(['status' => 'success']);
  } catch (TimeoutException $e) {
      // Queue for background processing
      $this->jobQueuer->queueViewUpdate($viewId);
      return new JsonResponse(
          ['status' => 'queued'],
          202 // Accepted
      );
  }
  ```

**File**: `src/Action/DataView/DataViewTestAction.php`
- **Route**: POST `/api/dataviews/test`
- **Body**: `{definition, dependencies}` (no need to save)
- **Returns**:
  ```json
  {
    "success": true,
    "output": {...},      // The JSON result
    "debugOutput": "..."  // Any extra output (for debugging)
  }
  ```
- **Note**: Execute Twig but don't save anything

#### 4.2 Admin Action

**File**: `src/Action/Admin/AdminDataViewsAction.php`
- **Route**: GET `/admin/dataviews`
- **Returns**: Rendered admin UI (Twig template)

#### 4.3 Routes Configuration

**File**: `config/routes/dataviews.php`
```php
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    // Admin UI
    $app->get('/admin/dataviews', AdminDataViewsAction::class)
        ->add(DataViewsAccessMiddleware::class);

    // API Routes
    $app->group('/api/dataviews', function (RouteCollectorProxy $group) {
        $group->get('', DataViewListAction::class);
        $group->post('', DataViewSaveAction::class);
        $group->post('/test', DataViewTestAction::class);
        $group->get('/{id}', DataViewFetchAction::class);
        $group->patch('/{id}', DataViewUpdateAction::class);
        $group->delete('/{id}', DataViewDeleteAction::class);
        $group->post('/{id}/rebuild', DataViewRebuildAction::class);
    })->add(DataViewsAccessMiddleware::class);
};
```

**Update**: `config/routes.php` - Include the new routes file

### Phase 5: Twig Integration

**Update**: `src/Domain/Twig/Extension/TotalCMSTwigExtension.php`

```php
// Add to getFunctions() method
new TwigFunction('view', [$this->adapter, 'view']),
```

**Update**: `src/Domain/Twig/Adapter/TotalCMSTwigAdapter.php`

```php
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

Add after Collections menu item (around line 75):
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
    // Add appropriate icon (suggestion: database/table icon)
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
  - Status (✓ success or ⚠️ error with hover tooltip)
- Action buttons per row:
  - Edit (pencil icon)
  - Test (play icon)
  - Rebuild (refresh icon)
  - Delete (trash icon)
- Empty state if no views exist

#### 6.3 View Editor

**File**: `resources/templates/admin/dataviews/edit.twig`

**Form Fields**:
1. **Name** (text input)
   - Auto-slugifies to ID on blur
   - Show computed ID below input

2. **Description** (textarea)
   - Optional, for documentation

3. **Dependencies** (multi-select)
   - List of all collections
   - User can select multiple

4. **Twig Definition** (code editor)
   - Syntax highlighting (reuse existing editor component)
   - Full-height editor
   - Explain that `viewData` variable will be captured

5. **Error Display** (if exists)
   - Show last error in red alert box
   - Include timestamp

**Buttons**:
- **Test Run**: Opens modal with JSON output preview
- **Save**: Creates/updates view and queues build
- **Cancel**: Return to list

#### 6.4 JavaScript

**File**: `javascript/admin/dataviews.js`

**Functionality**:
- Name → ID slugification (live preview)
- Test run AJAX:
  ```javascript
  async function testView() {
      const response = await fetch('/api/dataviews/test', {
          method: 'POST',
          body: JSON.stringify({
              definition: editor.getValue(),
              dependencies: getSelectedDependencies()
          })
      });
      const result = await response.json();
      showTestModal(result.output, result.debugOutput);
  }
  ```
- Form validation (name required, definition required)
- Confirmation on delete
- Auto-save indicator (optional)

**Build**: Include in ESBuild config

### Phase 7: JumpStart Integration

#### 7.1 Export

**Update**: `src/Domain/JumpStart/Service/JumpStartExporter.php`

Add method (call after `exportTemplates()`):
```php
private function exportDataViews(): void
{
    $views = $this->viewManager->listViews();

    foreach ($views as $view) {
        try {
            $definition = $this->viewRepository->fetchDefinition($view->id);

            $this->jumpstart->addDataView([
                'id'           => $view->id,
                'name'         => $view->name,
                'description'  => $view->description,
                'dependencies' => $view->dependencies,
                'definition'   => $definition,
            ]);
        } catch (Exception $e) {
            $this->addError("Data View {$view->id}: {$e->getMessage()}");
        }
    }
}
```

#### 7.2 Import

**Update**: `src/Domain/JumpStart/Service/JumpStartImporter.php`

Add method (call after `processTemplates()`):
```php
private function processDataViews(array $dataviews): void
{
    foreach ($dataviews as $view) {
        $viewId = $view['id'] ?? 'unknown';

        try {
            // Create view using ViewManager
            $this->viewManager->createView([
                'name'         => $view['name'],
                'description'  => $view['description'] ?? '',
                'dependencies' => $view['dependencies'] ?? [],
                'definition'   => $view['definition'] ?? '',
            ]);

            $this->addResult("Data View {$viewId}: created and queued for build");

        } catch (Exception $e) {
            $this->addError("Data View {$viewId}: {$e->getMessage()}");
        }
    }
}
```

#### 7.3 Data Structure

**Update**: `src/Domain/JumpStart/Data/JumpStartData.php`

Add property:
```php
/** @var array<array{id: string, name: string, description: string, dependencies: array, definition: string}> */
private array $dataviews = [];

public function addDataView(array $dataview): void
{
    $this->dataviews[] = $dataview;
}

public function getDataViews(): array
{
    return $this->dataviews;
}
```

**Export Order**:
1. Schemas
2. Collections
3. Templates
4. **Data Views** ← Add here
5. Objects
6. Factory

## Testing Strategy

### Unit Tests

**File**: `tests/Unit/DataView/ViewBuilderTest.php`
- Test Twig execution captures `viewData`
- Test JSON extraction from output
- Test invalid JSON handling
- Test error storage in metadata

**File**: `tests/Unit/DataView/ViewManagerTest.php`
- Test view creation (name slugification)
- Test duplicate ID prevention
- Test view update
- Test view deletion

**File**: `tests/Unit/DataView/ViewCacheManagerTest.php`
- Test cache set/get/clear
- Test cache key format
- Test TTL behavior

**File**: `tests/Unit/DataView/ViewUpdateSchedulerTest.php`
- Test finding dependent views
- Test job queuing for multiple views
- Test deduplication

### Integration Tests

**File**: `tests/Feature/DataViewCRUDTest.php`
- Test full CRUD lifecycle via API
- Test permissions (require `canAccessDataViews`)
- Test API response formats

**File**: `tests/Feature/DataViewBuildTest.php`
- Test view execution with real collections
- Test view data caching
- Test error handling and recovery

**File**: `tests/Feature/DataViewJobTest.php`
- Test job queue integration
- Test deduplication (multiple updates queue only once)
- Test job handles deleted views gracefully

**File**: `tests/Feature/DataViewCollectionHooksTest.php`
- Create view depending on collection
- Update collection object
- Verify view update job was queued
- Process job and verify view data updated

**File**: `tests/Feature/DataViewTwigIntegrationTest.php`
- Test `cms.view()` function in templates
- Test view-in-view access
- Test missing view handling

**File**: `tests/Feature/DataViewJumpStartTest.php`
- Export jumpstart with views
- Import jumpstart
- Verify views recreated and queued for build

## Configuration & Dependency Injection

**Update**: `config/container.php`

Register all new services:
```php
// Data View Services
DataViewRepository::class => autowire(),
ViewBuilder::class => autowire(),
ViewManager::class => autowire(),
ViewCacheManager::class => autowire(),
ViewUpdateScheduler::class => autowire(),

// Wire into existing services
ObjectSaver::class => function (ContainerInterface $c) {
    return new ObjectSaver(
        // ... existing dependencies
        $c->get(ViewUpdateScheduler::class)
    );
},
// Same for ObjectUpdater and ObjectRemover
```

## Documentation

### User Documentation

**File**: `resources/docs/data-views.md`

**Content**:
- What are Data Views?
- Use cases and examples
- Creating a view (step-by-step)
- Writing view Twig templates
- Understanding dependencies
- Testing and debugging views
- Performance considerations
- Limitations (stale data in view-in-view)

### Developer Documentation

Update CLAUDE.md with Data Views section:
- Storage location
- How views are built
- Job queue integration
- Caching strategy
- Extension points

## Migration Notes

**For Users**:
- No migration needed (new feature)
- Existing sites unaffected
- Permissions default to `false` (opt-in)

**For Developers**:
- New dependencies in container
- New routes need to be registered
- New permission needs to be added to access groups
- Job runner needs update to handle new job type

## Performance Considerations

1. **View Build Time**:
   - Large collections may take seconds/minutes to process
   - Job queue prevents timeouts
   - Consider adding progress indicators in future

2. **Cache Hit Rate**:
   - 30-minute TTL balances freshness vs performance
   - Monitor cache hit rates in admin UI (future enhancement)

3. **Job Queue Load**:
   - Deduplication prevents queue flooding
   - High-traffic sites may need job prioritization (future)

4. **Storage Size**:
   - View data files can grow large
   - Consider cleanup of old view data (future enhancement)

## Security Considerations

1. **Twig Sandbox**:
   - No additional sandboxing beyond normal Twig
   - Users can access same data as Playground
   - Permission-gated (admin-only by default)

2. **Input Validation**:
   - Validate view IDs (alphanumeric + hyphens only)
   - Sanitize user input in forms
   - CSRF protection on all actions

3. **File System**:
   - Store views in `.system` directory (not directly accessible)
   - Use `.htaccess` protection (like other data directories)

4. **API Access**:
   - All routes protected by DataViewsAccessMiddleware
   - Check permissions on every request

## Open Questions & Future Enhancements

### Deferred to Future Versions

1. **Parameterized Views**: `cms.view('posts', {author: 'joe'})`
   - Requires parameter caching strategy
   - Significant complexity increase

2. **Incremental Updates**: Only update changed data
   - Complex dependency tracking
   - Requires tracking what changed in collection update

3. **View-on-View Dependency Resolution**: Topological sort for build order
   - Detect circular dependencies
   - Build in correct order
   - Current limitation: Accept stale data

4. **Admin Notifications**: Email/dashboard alerts on view errors
   - Requires notification system
   - Could be useful but not critical

5. **Scheduled Rebuilds**: Cron-based view updates
   - Independent of collection changes
   - Useful for time-based views

6. **View Versioning**: History of view changes
   - Git-like version control
   - Rollback capability

7. **Performance Monitoring**: Dashboard with build times, cache hit rates
   - Requires metrics collection
   - Helpful for optimization

## Success Criteria

Feature is considered complete when:

- ✅ Users can create, edit, delete views via admin UI
- ✅ Views automatically update when dependent collections change
- ✅ View data is cached and performs well (< 10ms average access time)
- ✅ Test mode allows debugging Twig before saving
- ✅ JumpStart can export/import views
- ✅ Permissions properly restrict access
- ✅ Jobs handle errors gracefully and don't duplicate
- ✅ Documentation is complete and clear
- ✅ All tests pass (unit + integration)
- ✅ PHPStan Level 8 compliance maintained

## Estimated Effort

**Development Time**: 3-5 days for experienced developer familiar with Total CMS

**Breakdown**:
- Phase 1 (Infrastructure): 1 day
- Phase 2 (Building): 1 day
- Phase 3 (Integration): 0.5 days
- Phase 4 (API): 0.5 days
- Phase 5 (Twig): 0.25 days
- Phase 6 (UI): 1 day
- Phase 7 (JumpStart): 0.5 days
- Testing: 0.5 days
- Documentation: 0.25 days
- Polish & debugging: 0.5 days

## References

**Similar Features in Other Systems**:
- MySQL Materialized Views
- PostgreSQL Materialized Views
- GraphQL DataLoader
- Laravel Views/Cached Queries

**Related Total CMS Features**:
- Collection Indexes (similar caching strategy)
- Playground (similar permissions model)
- Templates (similar storage pattern)
- JumpStart (export/import pattern)
- Job Queue (async processing pattern)

---

**Note**: This is a planning document. Review and adjust as needed during implementation. The plan is intentionally
detailed to minimize implementation surprises, but expect some adjustments as development progresses.