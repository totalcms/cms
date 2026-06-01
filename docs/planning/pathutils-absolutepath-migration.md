# PathUtils::absolutePath() Migration (Tech Debt)

**Status:** Planned — small side project, **after Automations Plan 4**.
**Effort:** ~half a day (mechanical + a test pass).

## Goal

`PathUtils::absolutePath(string $base, string $relative): string` was added during the Automations work to centralize the "join a storage-relative path onto the data dir to get a real OS path" pattern. ~16 sites across the codebase still hand-roll that join, with drift:

- most use `$config->datadir . '/' . $x`
- `CollectionZipper` uses `DIRECTORY_SEPARATOR`
- some `rtrim()` the base, some assume no trailing slash, some don't `ltrim()` the relative part

Migrate them to the single helper so slash handling is consistent and the join lives in one place.

## The helper

```php
// src/Infrastructure/Filesystem/PathUtils.php
public static function absolutePath(string $base, string $relative): string
{
    return rtrim($base, '/\\') . '/' . ltrim($relative, '/\\');
}
```

Stateless by design — the base (`$config->datadir`) is passed in, so `PathUtils` stays free of a `Config` dependency. `config->datadir` is the canonical local root: the storage adapter is rooted there (`config/container.php` `StorageFilesystemAdapter` → `LocalFilesystemAdapter($config->datadir)`), and Flysystem deliberately hides its absolute root, so this is the right source.

## Sites to migrate

Authoritative list: `grep -rnE "config->datadir\s*\." src/` (≈16). Known at time of writing:

- `src/CLI/Command/JobsProcessCommand.php` — `.system/.processJobs.lock`
- `src/Domain/Cache/Service/CacheInvalidationSignal.php` — `.system/.cache_invalidate`
- `src/Domain/Setup/Service/SetupStateManager.php` (×2)
- `src/Domain/Setup/Repository/SetupStateRepository.php` (×2)
- `src/Domain/Bundle/Repository/BundleRepository.php` — `.system/.bundle`
- `src/Domain/Mailer/Repository/BulkMailerRepository.php` — `.system/bulkmailer`
- `src/Domain/Schema/Repository/SchemaRepository.php` — custom schema dir
- `src/Domain/ImageWorks/Service/ImageCacheService.php` (×2)
- `src/Domain/Export/Service/CollectionZipper.php` — **uses `DIRECTORY_SEPARATOR`** (the drift to fix)
- `src/Domain/Builder/Service/BuilderTemplatePaths.php` — builder dir
- `src/Domain/JobQueue/Repository/JobRepository.php` — `.system/jobqueue`

(`AutomationLoader::handler()` already uses the helper — the reference call site.)

## Notes

- Mechanical, low-risk, but spreads across CLI/Cache/Setup/Bundle/Mailer/Schema/ImageWorks/Export/Builder/JobQueue — so do it as its own PR with a full `composer run stan` + test pass, not bundled into a feature branch.
- Watch for callers that intentionally want OS-native separators (none expected — Flysystem + PHP both accept `/`).
