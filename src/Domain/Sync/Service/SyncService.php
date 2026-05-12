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
	 * Push schemas and templates to a remote server.
	 *
	 * @param list<string>|null $schemaFilter
	 * @param list<string>|null $templateFilter
	 */
	public function push(string $url, string $key, ?array $schemaFilter = null, ?array $templateFilter = null): OperationResult
	{
		$this->jumpStartExporter->setMetadata('Sync Push', 'Pushed via Total CMS sync');
		$jumpstart = $this->jumpStartExporter->exportSyncData($schemaFilter, $templateFilter);

		if ($jumpstart->isEmpty()) {
			return OperationResult::success('Nothing to push — no matching schemas or templates found.', [
				'schemas'   => 0,
				'templates' => 0,
			]);
		}

		$httpResponse = $this->httpClient->request('POST', $url . '/import/jumpstart', [
			'headers' => [
				'Authorization: Bearer ' . $key,
				'Content-Type: application/json',
				'Accept: application/json',
			],
			'body'    => $jumpstart->toJson(),
			'timeout' => 60,
		]);

		if ($httpResponse->statusCode >= 400) {
			$remoteResult = json_decode($httpResponse->body, true);
			$error        = is_array($remoteResult) ? ($remoteResult['error'] ?? $httpResponse->body) : $httpResponse->body;
			throw new \RuntimeException("Push failed (HTTP {$httpResponse->statusCode}): {$error}");
		}

		$remoteResult = json_decode($httpResponse->body, true);

		return OperationResult::success('Push complete.', [
			'schemas'       => count($jumpstart->schemas),
			'templates'     => count($jumpstart->templates),
			'remote_result' => is_array($remoteResult) ? $remoteResult : [],
		]);
	}

	/**
	 * Fetch sync data from a remote server without importing.
	 * Used for dry-run previews.
	 *
	 * @param list<string>|null $schemaFilter
	 * @param list<string>|null $templateFilter
	 *
	 * @return array<string,mixed> Filtered JumpStart payload
	 */
	public function fetchRemoteSyncData(string $url, string $key, ?array $schemaFilter = null, ?array $templateFilter = null): array
	{
		$httpResponse = $this->httpClient->request('GET', $url . '/export/jumpstart?mode=sync', [
			'headers' => [
				'Authorization: Bearer ' . $key,
				'Accept: application/json',
			],
			'timeout' => 60,
		]);

		if ($httpResponse->statusCode >= 400) {
			throw new \RuntimeException("Pull failed (HTTP {$httpResponse->statusCode})");
		}

		$payload = json_decode($httpResponse->body, true);
		if (!is_array($payload)) {
			throw new \RuntimeException('Pull failed: invalid response from remote.');
		}

		return $this->applyFilters($payload, $schemaFilter, $templateFilter);
	}

	/**
	 * Pull schemas and templates from a remote server.
	 *
	 * @param list<string>|null $schemaFilter
	 * @param list<string>|null $templateFilter
	 */
	public function pull(string $url, string $key, ?array $schemaFilter = null, ?array $templateFilter = null): OperationResult
	{
		$payload = $this->fetchRemoteSyncData($url, $key, $schemaFilter, $templateFilter);

		$schemaCount   = count($payload['schemas'] ?? []);
		$templateCount = count($payload['templates'] ?? []);

		if ($schemaCount === 0 && $templateCount === 0) {
			return OperationResult::success('Nothing to pull — no matching schemas or templates found.', [
				'schemas'   => 0,
				'templates' => 0,
			]);
		}

		$result = $this->jumpStartImporter->importFromDefinition($payload);

		return OperationResult::success('Pull complete.', [
			'schemas'       => $schemaCount,
			'templates'     => $templateCount,
			'import_result' => $result,
		]);
	}

	/**
	 * Filter a JumpStart payload by schema and template IDs.
	 *
	 * @param array<string,mixed>  $payload
	 * @param list<string>|null    $schemaFilter
	 * @param list<string>|null    $templateFilter
	 *
	 * @return array<string,mixed>
	 */
	private function applyFilters(array $payload, ?array $schemaFilter, ?array $templateFilter): array
	{
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

		return $payload;
	}
}
