<?php

declare(strict_types=1);

namespace TotalCMS\Action\Sync;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;

/**
 * Source endpoint for sync pulls from another T3 instance.
 *
 * Functionally this is `GET /api/export/jumpstart?mode=sync`, which is what
 * sync pull originally called. It exists as a dedicated route so that ALL
 * server-to-server sync traffic lives under `/sync/*` — which lets the
 * API-key endpoint picker offer a single "Sync Manager" option (`/sync`)
 * that covers both directions, instead of asking the operator to reason
 * about which of two unrelated path grants each direction needs. The old
 * docs told operators to grant `/export/*` and `/import/*`; neither was an
 * option in the picker, and the second was wrong anyway (push receives on
 * `/sync/import`).
 *
 * The response is the raw sync JumpStart payload as JSON — no download
 * headers, this is a machine consumer. Filtering happens on the pulling
 * side (SyncService::applyFilters), same as it always has.
 */
readonly class SyncExportAction
{
	public function __construct(
		private JumpStartExporter $jumpStartExporter,
	) {
	}

	/** @SuppressWarnings("PHPMD.ErrorControlOperator") */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$this->jumpStartExporter->setMetadata('Sync Export', 'Pulled via Total CMS sync');
		$jumpStartData = $this->jumpStartExporter->exportSyncData();

		// Stream rather than json_encode the whole payload: allowlisted
		// collections (builder-pages especially) can be large, and the
		// exporter already knows how to stream itself to a handle.
		$tempFile = \function_exists('tmpfile') ? @\tmpfile() : false;
		if ($tempFile === false) {
			$tempFile = \fopen('php://temp', 'r+');
		}
		if ($tempFile === false) {
			throw new \RuntimeException('Failed to create temporary file for sync export');
		}

		$jumpStartData->streamJsonToFile($tempFile);
		\rewind($tempFile);

		return $response
			->withHeader('Content-Type', 'application/json')
			->withBody(Stream::create($tempFile));
	}
}
