<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sync\Service;

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
	) {
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
	 */
	public function push(
		string $url,
		string $key,
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
	): OperationResult {
		$this->jumpStartExporter->setMetadata('Sync Push', 'Pushed via Total CMS sync');
		$jumpstart = $this->jumpStartExporter->exportSyncData($schemaFilter, $templateFilter, $collectionsFilter);

		if ($jumpstart->isEmpty()) {
			return OperationResult::success('Nothing to push — no matching schemas, templates, or collections found.', [
				'schemas'     => 0,
				'templates'   => 0,
				'collections' => 0,
			]);
		}

		// Remote import/export endpoints live under the API group at
		// `/api/import/jumpstart` and `/api/export/jumpstart`. Users configure
		// the remote `url` setting as the bare site URL (per the placeholder
		// `https://example.com/tcms` in settings/sync.json), so we append the
		// full route path here. rtrim guards against accidentally-trailing
		// slashes producing a double-slash.
		// Use the X-API-Key header instead of `Authorization: Bearer` because
		// OAuthBearerMiddleware (outer layer on the /api/ group since Phase 4)
		// intercepts any Bearer token and tries to validate it as a JWT —
		// plain API keys aren't JWTs, so the request would 401 before
		// DualAuthMiddleware/ApiKeyAuthMiddleware (which accept both header
		// formats) ever ran. X-API-Key is invisible to OAuthBearerMiddleware
		// and falls through to the API-key validator cleanly.
		$httpResponse = $this->httpClient->request('POST', rtrim($url, '/') . '/api/import/jumpstart', [
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

		$remoteResult = json_decode($httpResponse->body, true);

		return OperationResult::success('Push complete.', [
			'schemas'       => count($jumpstart->schemas),
			'templates'     => count($jumpstart->templates),
			'collections'   => count($jumpstart->objects),
			'remote_result' => is_array($remoteResult) ? $remoteResult : [],
		]);
	}

	/**
	 * Fetch sync data from a remote server without importing.
	 * Used for dry-run previews.
	 *
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 *
	 * @return array<string,mixed> Filtered JumpStart payload
	 */
	public function fetchRemoteSyncData(
		string $url,
		string $key,
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
	): array {
		$httpResponse = $this->httpClient->request('GET', rtrim($url, '/') . '/api/export/jumpstart?mode=sync', [
			'headers' => [
				// See push() for why X-API-Key rather than Authorization: Bearer.
				'X-API-Key: ' . $key,
				'Accept: application/json',
			],
			'timeout' => 60,
		]);

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

		return $this->applyFilters($payload, $schemaFilter, $templateFilter, $collectionsFilter);
	}

	/**
	 * Pull schemas, templates, and objects from sync-allowlisted collections
	 * from a remote server. See push() for the collectionsFilter map shape.
	 *
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 */
	public function pull(
		string $url,
		string $key,
		?array $schemaFilter = null,
		?array $templateFilter = null,
		?array $collectionsFilter = null,
	): OperationResult {
		$payload = $this->fetchRemoteSyncData($url, $key, $schemaFilter, $templateFilter, $collectionsFilter);

		$schemaCount     = count($payload['schemas'] ?? []);
		$templateCount   = count($payload['templates'] ?? []);
		$collectionCount = count($payload['objects'] ?? []);

		if ($schemaCount === 0 && $templateCount === 0 && $collectionCount === 0) {
			return OperationResult::success('Nothing to pull — no matching schemas, templates, or collections found.', [
				'schemas'     => 0,
				'templates'   => 0,
				'collections' => 0,
			]);
		}

		$result = $this->jumpStartImporter->importFromDefinition($payload);

		return OperationResult::success('Pull complete.', [
			'schemas'       => $schemaCount,
			'templates'     => $templateCount,
			'collections'   => $collectionCount,
			'import_result' => $result,
		]);
	}

	/**
	 * Filter a JumpStart payload by schema ids, template ids, and a
	 * per-collection object-id map (see push() for the map shape).
	 *
	 * @param array<string,mixed>                     $payload
	 * @param list<string>|null                       $schemaFilter
	 * @param list<string>|null                       $templateFilter
	 * @param array<string,list<string>|null>|null    $collectionsFilter
	 *
	 * @return array<string,mixed>
	 */
	private function applyFilters(
		array $payload,
		?array $schemaFilter,
		?array $templateFilter,
		?array $collectionsFilter = null,
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
