<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\PasskeyService;
use TotalCMS\Renderer\JsonRenderer;

readonly class PasskeyDeleteAction
{
	public function __construct(
		private PasskeyService $passkeyService,
		private AccessManager $accessManager,
		private JsonRenderer $jsonRenderer,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$user         = $this->accessManager->userData();
		$userId       = (string)($user['id'] ?? '');
		$collection   = (string)($user['collection'] ?? '');
		$credentialId = $args['credentialId'] ?? '';

		$this->passkeyService->deletePasskey($userId, $collection, $credentialId);

		// HTMX requests get a retarget header to refresh the passkey list
		if ($request->getHeaderLine('HX-Request') === 'true') {
			$response = $response->withHeader('HX-Trigger', 'passkey-changed');
		}

		return $this->jsonRenderer->json($response, ['success' => true]);
	}
}
