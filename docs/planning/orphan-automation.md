# Automated Orphan Cleanup (Future Phase)

## Overview

Automatically detect and clean up orphaned relational references when objects are deleted, via the job queue.

## Approach

When an object is deleted from a collection, queue a background job that scans all collections for references to the deleted object and cleans them up.

## Setting: `autoCleanOrphans` in relationalOptions

Add an optional `autoCleanOrphans` boolean to `relationalOptions` schema settings. When `true`, the system will automatically clean up this property when the referenced object is deleted.

```json
{
  "settings": {
    "relationalOptions": {
      "collection": "authors",
      "label": "name",
      "value": "id",
      "autoCleanOrphans": true
    }
  }
}
```

This gives users per-property control over whether orphans are auto-cleaned.

## Implementation Details

### 1. `src/Domain/JobQueue/Data/JobData.php`
- Add `TYPE_ORPHAN_SCAN = 'orphan_scan'` constant
- Add to `TYPE_LIST` array

### 2. `src/Domain/Object/Service/ObjectRemover.php`
- Add `JobQueuer` as constructor dependency
- After successful deletion, queue an orphan scan job:
  ```php
  $this->jobQueuer->queueJob(JobData::TYPE_ORPHAN_SCAN, $collection, ['deletedId' => $id]);
  ```

### 3. `src/Domain/JobQueue/Service/JobRunner.php`
- Add `OrphanScanner` and `OrphanCleaner` as constructor dependencies
- Add `TYPE_ORPHAN_SCAN` case in `processJob()` switch
- `processOrphanScanJob()`: decodes payload, calls `scanForReferencesTo()`, then checks which properties have `autoCleanOrphans: true` before cleaning

### 4. `src/Domain/JobQueue/Service/JobQueuer.php`
- Add `queueOrphanScan(string $collection, string $deletedId)` helper method

### 5. `OrphanScanner::scanForReferencesTo()`
- Targeted scan: only checks collections/properties that reference the target collection
- Returns list of affected references with the `autoCleanOrphans` flag from the schema
- Much more efficient than a full scan since it only reads relevant collections

## Considerations

- **Performance**: The job runs async so it doesn't slow down the delete operation
- **Opt-in**: Only properties with `autoCleanOrphans: true` are auto-cleaned
- **Logging**: All auto-cleanup actions logged to `orphan-cleanup.log`
- **Safety**: Manual scanner (Phase 1/2) can still be used regardless of this setting
