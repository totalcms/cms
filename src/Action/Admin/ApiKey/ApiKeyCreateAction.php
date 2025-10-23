<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\ApiKey;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyCreator;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Create a new API key.
 */
readonly class ApiKeyCreateAction
{
	public function __construct(
		private ApiKeyCreator $apiKeyCreator,
		private JsonRenderer $jsonRenderer,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$data = (array)$request->getParsedBody();

		// Parse request data
		$name         = (string)($data['name'] ?? '');
		$endpointType = (string)($data['endpoint-type'] ?? 'specific');

		$scopes = [
			'methods' => $data['methods'] ?? [],
			'paths'   => $endpointType === 'all' ? ['*'] : ($data['paths'] ?? []),
		];

		try {
			// Create the API key (validation happens in the service)
			$apiKey = $this->apiKeyCreator->createApiKey($name, $scopes);

			return $this->jsonRenderer->json($response->withStatus(201), [
				'success' => true,
				'message' => 'API key created successfully',
				'apiKey'  => $apiKey->toArray(),
			]);
		} catch (\InvalidArgumentException $e) {
			return $this->jsonRenderer->json($response->withStatus(400), [
				'error' => ['message' => $e->getMessage()],
			]);
		}
	}
}
