<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sync\Service;

use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\OperationResult;

/**
 * Shared sync service used by both the CLI and admin dashboard.
 *
 * Handles pushing schemas/templates to a remote T3 instance
 * and pulling them from a remote instance.
 */
readonly class SyncService
{
	public function __construct(
		private JumpStartExporter $jumpStartExporter,
		private JumpStartImporter $jumpStartImporter,
		private HttpClientInterface $httpClient,
		private BuilderTemplatePaths $paths,
		private SyncDiffService $diffService,
	) {
	}

	/**
	 * Compare local sync state against the remote's, item by item.
	 *
	 * One orchestration — export local, fetch remote, diff — shared by the
	 * CLI dry-runs and the admin Sync Manager, so every surface always
	 * reports the same statuses. See SyncDiffService for the semantics
	 * (content decides differs/same, timestamps only hint direction).
	 *
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 * @param list<string>|null                       $collectionMetaFilter Collection SETTINGS to include (tristate)
	 *
	 * @throws \RuntimeException When the remote cannot be reached or answers with an error
	 *
	 * @return array{schemas:array<string,mixed>,templates:array<string,mixed>,objects:array<string,mixed>,collections:array<string,mixed>}
	 */
	public function diff(
		string $url,
		string $key,
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
		?array $collectionMetaFilter = null,
	): array {
		$templateFilter = $this->syncableTemplateFilter($templateFilter);

		$this->jumpStartExporter->setMetadata('Sync Diff', 'Local state for sync comparison');
		$local  = $this->jumpStartExporter->exportSyncData($schemaFilter, $templateFilter, $collectionsFilter, $collectionMetaFilter)->toArray();
		$remote = $this->fetchRemoteSyncData($url, $key, $schemaFilter, $templateFilter, $collectionsFilter, $collectionMetaFilter);

		return $this->diffService->diff($local, $remote);
	}

	/**
	 * Git-managed projects deliver templates via git, not sync (Decision 8 of
	 * the git-first template workflow). Force the template filter to "none"
	 * ([]) so push/pull carry page records and content but never templates —
	 * each artifact keeps a single delivery channel.
	 *
	 * Public so the CLI dry-runs can apply the same rule to their LOCAL
	 * export: without it, a git-managed site's preview lists templates that
	 * a real push or pull would never move.
	 *
	 * @param list<string>|null $templateFilter
	 *
	 * @return list<string>|null
	 */
	public function syncableTemplateFilter(?array $templateFilter): ?array
	{
		return $this->paths->isProjectManaged() ? [] : $templateFilter;
	}

	/**
	 * Push schemas, templates, and objects from sync-allowlisted collections
	 * to a remote server.
	 *
	 * Schemas/templates use a flat tristate: null = all, [] = none, list = ids.
	 * Collections use a per-collection map because the UI presents each
	 * allowlisted collection (Pages, Mailer, Prompts, Dataviews) as its
	 * own section with its own all/specific/none picker over the contained
	 * object ids:
	 *   - null               → every object in every allowlisted collection
	 *   - ['id' => null]     → every object in that collection
	 *   - ['id' => [...ids]] → only those object ids
	 *   - key absent         → skip that collection
	 *
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 * @param list<string>|null                       $collectionMetaFilter Collection SETTINGS to include (tristate)
	 */
	public function push(
		string $url,
		string $key,
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
		?array $collectionMetaFilter = null,
	): OperationResult {
		$templateFilter = $this->syncableTemplateFilter($templateFilter);

		$this->jumpStartExporter->setMetadata('Sync Push', 'Pushed via Total CMS sync');
		$jumpstart = $this->jumpStartExporter->exportSyncData($schemaFilter, $templateFilter, $collectionsFilter, $collectionMetaFilter);

		if ($jumpstart->isEmpty()) {
			return OperationResult::success('Nothing to push — no matching schemas, templates, or collections found.', [
				'schemas'     => 0,
				'templates'   => 0,
				'collections' => 0,
			]);
		}

		// Push to the sync-specific receive endpoint, NOT the general
		// `/api/import/jumpstart` route. The two endpoints behave the same
		// way auth-wise (both DualAuthMiddleware), but `/api/sync/import`
		// runs the importer in upsert mode so a sync push lands as a true
		// mirror of local rather than silently skipping rows that already
		// exist on the remote (the starter-kit semantics that
		// `/api/import/jumpstart` is built around).
		// Use the X-API-Key header instead of `Authorization: Bearer` because
		// OAuthBearerMiddleware (outer layer on the /api/ group since Phase 4)
		// intercepts any Bearer token and tries to validate it as a JWT —
		// plain API keys aren't JWTs, so the request would 401 before
		// DualAuthMiddleware/ApiKeyAuthMiddleware (which accept both header
		// formats) ever ran. X-API-Key is invisible to OAuthBearerMiddleware
		// and falls through to the API-key validator cleanly.
		// rtrim guards against accidentally-trailing slashes producing a
		// double-slash in the request URL.
		$httpResponse = $this->httpClient->request('POST', rtrim($url, '/') . '/api/sync/import', [
			'headers' => [
				'X-API-Key: ' . $key,
				'Content-Type: application/json',
				'Accept: application/json',
			],
			'body'    => $jumpstart->toJson(),
			'timeout' => 60,
		]);

		if ($httpResponse->statusCode >= 400) {
			throw new \RuntimeException(sprintf(
				'Push failed (HTTP %d): %s',
				$httpResponse->statusCode,
				$this->extractRemoteError($httpResponse->body),
			));
		}

		$decoded      = json_decode($httpResponse->body, true);
		$remoteResult = is_array($decoded) ? $decoded : [];

		$counts = [
			'schemas'       => count($jumpstart->schemas),
			'templates'     => count($jumpstart->templates),
			'collections'   => $this->countCollections($jumpstart->collections),
			'objects'       => count($jumpstart->objects),
			'remote_result' => $remoteResult,
		];

		// A 2xx only means the payload was accepted for import. The receiving
		// importer collects per-item failures and still answers 200, so the
		// transport succeeding says nothing about whether anything was written.
		// Reporting "Push complete." here hid errors the remote had already
		// diagnosed precisely — e.g. "Cannot save collection with a reserved
		// name" — and made a wholly failed push read as a clean one.
		$remoteErrors = self::stringList($remoteResult['errors'] ?? null);
		if (($remoteResult['success'] ?? true) === false || $remoteErrors !== []) {
			return OperationResult::failure(
				'Push rejected by the remote.',
				$remoteErrors === [] ? null : implode('; ', $remoteErrors),
				$counts,
			);
		}

		return OperationResult::success('Push complete.', $counts);
	}

	/**
	 * Count both arms of a JumpStart `collections` block.
	 *
	 * Collections are stored as `['reserved' => [...], 'custom' => [...]]`,
	 * so a plain count() over the wrapper always returns 2 and counting
	 * `objects` instead (as this once did) reports 0 for a settings-only
	 * sync — the exact case that looks like a silent no-op.
	 *
	 * @param array<string,mixed> $collections
	 */
	private function countCollections(array $collections): int
	{
		$reserved = is_array($collections['reserved'] ?? null) ? $collections['reserved'] : [];
		$custom   = is_array($collections['custom'] ?? null) ? $collections['custom'] : [];

		return count($reserved) + count($custom);
	}

	/**
	 * Normalise a payload's error bag to a list of strings.
	 *
	 * @return list<string>
	 */
	private static function stringList(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		$out = [];
		foreach ($value as $entry) {
			if (is_scalar($entry) || $entry instanceof \Stringable) {
				$out[] = (string)$entry;
			}
		}

		return $out;
	}

	/**
	 * Fetch sync data from a remote server without importing.
	 * Used for dry-run previews.
	 *
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 * @param list<string>|null                       $collectionMetaFilter Collection SETTINGS to include (tristate)
	 *
	 * @return array<string,mixed> Filtered JumpStart payload
	 */
	public function fetchRemoteSyncData(
		string $url,
		string $key,
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
		?array $collectionMetaFilter = null,
	): array {
		// `/api/sync/export` is the canonical pull source: it lives under
		// /sync so the "Sync Manager" API-key endpoint option covers both
		// directions with one path grant. Fall back to the legacy
		// `/api/export/jumpstart?mode=sync` on any 4xx — that keeps two real
		// cases working: a remote on an older release that doesn't have the
		// route yet (404), and an API key created before the Sync Manager
		// option existed whose grant covers /export but not /sync (403).
		$requestOptions = [
			'headers' => [
				// See push() for why X-API-Key rather than Authorization: Bearer.
				'X-API-Key: ' . $key,
				'Accept: application/json',
			],
			'timeout' => 60,
		];

		$httpResponse = $this->httpClient->request('GET', rtrim($url, '/') . '/api/sync/export', $requestOptions);

		if ($httpResponse->statusCode >= 400 && $httpResponse->statusCode < 500) {
			$httpResponse = $this->httpClient->request('GET', rtrim($url, '/') . '/api/export/jumpstart?mode=sync', $requestOptions);
		}

		if ($httpResponse->statusCode >= 400) {
			throw new \RuntimeException(sprintf(
				'Pull failed (HTTP %d): %s',
				$httpResponse->statusCode,
				$this->extractRemoteError($httpResponse->body),
			));
		}

		$payload = json_decode($httpResponse->body, true);
		if (!is_array($payload)) {
			throw new \RuntimeException('Pull failed: invalid response from remote.');
		}

		return $this->applyFilters($payload, $schemaFilter, $this->syncableTemplateFilter($templateFilter), $collectionsFilter, $collectionMetaFilter);
	}

	/**
	 * Pull schemas, templates, and objects from sync-allowlisted collections
	 * from a remote server. See push() for the collectionsFilter map shape.
	 *
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 * @param list<string>|null                       $collectionMetaFilter Collection SETTINGS to include (tristate)
	 */
	public function pull(
		string $url,
		string $key,
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
		?array $collectionMetaFilter = null,
	): OperationResult {
		$payload = $this->fetchRemoteSyncData($url, $key, $schemaFilter, $templateFilter, $collectionsFilter, $collectionMetaFilter);

		$schemaCount   = count($payload['schemas'] ?? []);
		$templateCount = count($payload['templates'] ?? []);
		$objectCount   = count($payload['objects'] ?? []);
		// Collections were previously counted from `objects` and left out of the
		// guard below entirely, so a settings-only pull short-circuited to
		// "Nothing to pull" and never reached the importer at all.
		$collectionCount = $this->countCollections(
			is_array($payload['collections'] ?? null) ? $payload['collections'] : [],
		);

		if ($schemaCount === 0 && $templateCount === 0 && $objectCount === 0 && $collectionCount === 0) {
			return OperationResult::success('Nothing to pull — no matching schemas, templates, or collections found.', [
				'schemas'     => 0,
				'templates'   => 0,
				'collections' => 0,
				'objects'     => 0,
			]);
		}

		// Sync semantics: production is treated as the source of truth on
		// pull, so existing local rows are overwritten rather than skipped.
		// Same authoritative-source rule as push, just in the other
		// direction. Public `/api/import/jumpstart` keeps its skip-existing
		// default for the starter-kit flow.
		// Pull is initiated by the operator from their local shell (`tcms pull`),
		// so it carries shell trust and may apply code-executing system
		// collections mirrored down from production.
		$result = $this->jumpStartImporter->importFromDefinition($payload, true, allowSystemCollections: true);

		$counts = [
			'schemas'       => $schemaCount,
			'templates'     => $templateCount,
			'collections'   => $collectionCount,
			'objects'       => $objectCount,
			'import_result' => $result->toArray(),
		];

		// Same contract as push(): the importer reports per-item failures in its
		// own result rather than throwing, so returning success unconditionally
		// would report a clean pull over a refused import.
		$importErrors = self::stringList($result->data['errors'] ?? null);
		if (!$result->success || $importErrors !== []) {
			return OperationResult::failure(
				'Pull completed with errors.',
				$importErrors === [] ? null : implode('; ', $importErrors),
				$counts,
			);
		}

		return OperationResult::success('Pull complete.', $counts);
	}

	/**
	 * Filter a JumpStart payload by schema ids, template ids, and a
	 * per-collection object-id map (see push() for the map shape).
	 *
	 * @param array<string,mixed>                     $payload
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 * @param list<string>|null                       $collectionMetaFilter Collection SETTINGS to include (tristate)
	 *
	 * @return array<string,mixed>
	 */
	private function applyFilters(
		array $payload,
		?array $schemaFilter,
		?array $templateFilter,
		?array $collectionsFilter = null,
		?array $collectionMetaFilter = null,
	): array {
		if ($schemaFilter !== null && isset($payload['schemas']) && is_array($payload['schemas'])) {
			$payload['schemas'] = array_values(array_filter(
				$payload['schemas'],
				fn (array $s): bool => in_array($s['id'] ?? '', $schemaFilter, true)
			));
		}
		if ($templateFilter !== null && isset($payload['templates']) && is_array($payload['templates'])) {
			$payload['templates'] = array_values(array_filter(
				$payload['templates'],
				fn (array $t): bool => in_array($t['id'] ?? '', $templateFilter, true)
			));
		}
		if ($collectionsFilter !== null && isset($payload['objects']) && is_array($payload['objects'])) {
			$payload['objects'] = array_values(array_filter(
				$payload['objects'],
				function (array $o) use ($collectionsFilter): bool {
					$cid = (string)($o['collection'] ?? '');
					$oid = (string)($o['id'] ?? '');
					if (!array_key_exists($cid, $collectionsFilter)) {
						return false;
					}
					$ids = $collectionsFilter[$cid];

					return $ids === null || in_array($oid, $ids, true);
				}
			));
		}

		if ($collectionMetaFilter !== null && isset($payload['collections']) && is_array($payload['collections'])) {
			foreach (['custom', 'reserved'] as $kind) {
				if (!isset($payload['collections'][$kind]) || !is_array($payload['collections'][$kind])) {
					continue;
				}
				$payload['collections'][$kind] = array_values(array_filter(
					$payload['collections'][$kind],
					function (mixed $entry) use ($collectionMetaFilter): bool {
						$id = is_string($entry) ? $entry : (string)(is_array($entry) ? ($entry['id'] ?? '') : '');

						return in_array($id, $collectionMetaFilter, true);
					}
				));
			}
		}

		return $payload;
	}

	/**
	 * Pull a usable string error message out of a remote response body.
	 *
	 * T3's error responses typically look like `{"error": {"message": "...",
	 * "code": "..."}}` (nested object). The original implementation read
	 * `$body['error']` and stringified it, which for the nested-object case
	 * produces the literal string "Array" — useless for debugging. Handle
	 * the nested shape, fall back to flat error/message strings, and as a
	 * last resort return a trimmed slice of the raw body.
	 */
	private function extractRemoteError(string $body): string
	{
		$decoded = json_decode($body, true);

		if (is_array($decoded)) {
			$error = $decoded['error'] ?? null;

			if (is_array($error) && is_string($error['message'] ?? null)) {
				return $error['message'];
			}
			if (is_string($error)) {
				return $error;
			}
			if (is_string($decoded['message'] ?? null)) {
				return $decoded['message'];
			}
		}

		$trimmed = trim($body);
		if ($trimmed === '') {
			return '(empty response body)';
		}

		return strlen($trimmed) > 500 ? substr($trimmed, 0, 500) . '…' : $trimmed;
	}
}
