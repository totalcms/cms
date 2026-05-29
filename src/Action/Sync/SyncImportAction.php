<?php

declare(strict_types=1);

namespace TotalCMS\Action\Sync;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Receive endpoint for sync pushes from another T3 instance.
 *
 * Distinct from `ImportJumpStartAction` (`POST /api/import/jumpstart`)
 * because the two endpoints intentionally have opposite default
 * write semantics:
 *
 *   - `/api/import/jumpstart` — starter-kit / re-runnable import.
 *     Skip existing objects so an operator can re-run a starter
 *     kit without their post-install edits being trampled.
 *
 *   - `/api/sync/import` — server-to-server sync push. The pushing
 *     side is authoritative, so existing objects are overwritten via
 *     ObjectUpdater (createOrUpdate). The route's existence is the
 *     contract — no flag to forget.
 *
 * Body is always JSON. There is no multipart / file-upload branch
 * here on purpose: nothing on the sender side ever needs to upload
 * a `.json` file to a remote.
 */
readonly class SyncImportAction
{
	public function __construct(
		private JumpStartImporter $jumpStartImporter,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$body = (string)$request->getBody();
		if ($body === '') {
			throw new HttpBadRequestException($request, 'Empty request body');
		}

		try {
			$definition = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new HttpBadRequestException($request, 'Invalid JSON: ' . $e->getMessage());
		}

		if (!is_array($definition)) {
			throw new HttpBadRequestException($request, 'JSON root must be an object');
		}

		$result = $this->jumpStartImporter->importFromDefinition($definition, true);

		return $this->renderer->json($response, $result->toArray());
	}
}
