<?php

declare(strict_types=1);

namespace TotalCMS\Action\Sync;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyGrants;
use TotalCMS\Domain\Auth\Service\AccessManager;
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
		private AccessManager $accessManager,
	) {
	}

	/**
	 * A sync push that mirrors `automations` (code-executing system
	 * collection) is RCE-grade on the receiving side, so it needs a
	 * super-admin's authority: their session, or an API key with the Sync
	 * Manager permission. Only a super-admin can create a key, and one given
	 * that permission was made to push what sync moves, automations included.
	 * A signed-in user who is not a super-admin is still refused, as
	 * SystemCollectionGuardMiddleware refuses them on the generic write path.
	 */
	private function mayWriteSystemCollections(ServerRequestInterface $request): bool
	{
		if ($this->accessManager->sessionIsSuperAdmin()) {
			return true;
		}

		$apiKey = $request->getAttribute('apiKey');

		return $request->getAttribute('authMethod') === 'apikey'
			&& $apiKey instanceof ApiKeyData
			&& ApiKeyGrants::grantsSync($apiKey);
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

		$result = $this->jumpStartImporter->importFromDefinition($definition, true, $this->mayWriteSystemCollections($request));

		return $this->renderer->json($response, $result->toArray());
	}
}
