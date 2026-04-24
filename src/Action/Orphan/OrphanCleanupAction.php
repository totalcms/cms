<?php

declare(strict_types=1);

namespace TotalCMS\Action\Orphan;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Orphan\Service\OrphanCleaner;
use TotalCMS\Domain\Orphan\Service\OrphanScanner;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\OperationResult;

readonly class OrphanCleanupAction
{
	public function __construct(
		private OrphanScanner $scanner,
		private OrphanCleaner $cleaner,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$body = (array)$request->getParsedBody();
		$mode = (string)($body['mode'] ?? '');

		// Handle explicit entries mode (from UI checkboxes)
		$entries = $body['entries'] ?? null;
		if (is_array($entries) && $entries !== []) {
			$result = $this->cleanExplicitEntries($entries);

			return $this->renderer->json($response, $result->toArray());
		}

		// Mode-based cleanup: scan then clean
		$report = null;
		$result = null;

		switch ($mode) {
			case 'all':
				$report = $this->scanner->scanAll();
				$result = $this->cleaner->cleanAll($report);
				break;

			case 'collection':
				$collection = (string)($body['collection'] ?? '');
				if ($collection === '') {
					$response = $response->withStatus(400);

					return $this->renderer->json($response, ['error' => 'Missing collection parameter']);
				}
				$report = $this->scanner->scanCollection($collection);
				$result = $this->cleaner->cleanByCollection($report, $collection);
				break;

			case 'property':
				$collection = (string)($body['collection'] ?? '');
				$property   = (string)($body['property'] ?? '');
				if ($collection === '' || $property === '') {
					$response = $response->withStatus(400);

					return $this->renderer->json($response, ['error' => 'Missing collection or property parameter']);
				}
				$report = $this->scanner->scanCollection($collection);
				$result = $this->cleaner->cleanByCollectionProperty($report, $collection, $property);
				break;

			default:
				$response = $response->withStatus(400);

				return $this->renderer->json($response, ['error' => 'Invalid mode. Use: all, collection, property, or provide entries array']);
		}

		return $this->renderer->json($response, $result->toArray());
	}

	/**
	 * Clean explicitly specified entries from UI selection.
	 *
	 * @param array<mixed> $entries
	 */
	private function cleanExplicitEntries(array $entries): OperationResult
	{
		$cleaned = 0;
		$failed  = 0;
		/** @var array<string> $errors */
		$errors = [];

		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$collection  = (string)($entry['collection'] ?? '');
			$objectId    = (string)($entry['objectId'] ?? '');
			$property    = (string)($entry['property'] ?? '');
			$orphanedIds = $entry['orphanedIds'] ?? [];
			$isArray     = (bool)($entry['isArray'] ?? false);

			if ($collection === '' || $objectId === '' || $property === '' || !is_array($orphanedIds)) {
				$failed++;
				$errors[] = 'Invalid entry data';
				continue;
			}

			/** @var array<string> $orphanedIds */
			$result = $this->cleaner->cleanProperty($collection, $objectId, $property, $orphanedIds, $isArray);

			if ($result->success) {
				$cleaned++;
			} else {
				$failed++;
				$errors[] = "{$collection}/{$objectId}.{$property}: {$result->error}";
			}
		}

		return OperationResult::success('', [
			'cleaned' => $cleaned,
			'failed'  => $failed,
			'errors'  => $errors,
		]);
	}
}
