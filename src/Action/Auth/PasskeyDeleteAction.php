<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
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

	/**
	 * @param array<string,string> $args
	 *
	 * @throws HttpNotFoundException when the signed-in user has no passkey with that id
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$user         = $this->accessManager->userData();
		$userId       = (string)($user['id'] ?? '');
		$collection   = (string)($user['collection'] ?? '');
		$credentialId = $args['credentialId'] ?? '';

		if (!$this->passkeyService->deletePasskey($userId, $collection, $credentialId)) {
			throw new HttpNotFoundException($request, 'Passkey not found');
		}

		// HTMX requests get a retarget header to refresh the passkey list
		if ($request->getHeaderLine('HX-Request') === 'true') {
			$response = $response->withHeader('HX-Trigger', 'passkey-changed');
		}

		return $this->jsonRenderer->json($response, ['success' => true]);
	}
}
