<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\PasskeyService;
use TotalCMS\Renderer\JsonRenderer;

readonly class PasskeyRegisterAction
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
		$user       = $this->accessManager->userData();
		$userId     = (string)($user['id'] ?? '');
		$collection = (string)($user['collection'] ?? '');

		$body = (string)$request->getBody();
		$data = (array)json_decode($body, true);
		$name = (string)($data['name'] ?? '');

		// The client response is the full body (the credential JSON from navigator.credentials.create)
		$clientResponse = $body;

		// If the body wraps credential + name, extract the credential portion
		if (isset($data['credential'])) {
			$clientResponse = json_encode($data['credential']) ?: '';
		}

		try {
			$result = $this->passkeyService->verifyRegistration($userId, $collection, $clientResponse, $name);

			return $this->jsonRenderer->json($response->withStatus(201), [
				'success' => true,
				...$result,
			]);
		} catch (\Throwable $e) {
			return $this->jsonRenderer->json($response->withStatus(400), [
				'success' => false,
				'error'   => $e->getMessage(),
			]);
		}
	}
}
