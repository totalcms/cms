<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\ApiKey;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyDeleter;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Delete an API key.
 */
readonly class ApiKeyDeleteAction
{
	public function __construct(
		private ApiKeyDeleter $apiKeyDeleter,
		private JsonRenderer $jsonRenderer,
	) {
	}

	/**
	 * @param array<string,mixed> $args
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$id = (string)($args['id'] ?? '');

		if ($id === '') {
			return $this->jsonRenderer->json($response->withStatus(400), [
				'error' => [
					'message' => 'API key ID is required',
				],
			]);
		}

		$deleted = $this->apiKeyDeleter->deleteKey($id);

		if (!$deleted) {
			return $this->jsonRenderer->json($response->withStatus(404), [
				'error' => [
					'message' => 'API key not found',
				],
			]);
		}

		return $this->jsonRenderer->json($response, [
			'success' => true,
			'message' => 'API key deleted successfully',
		]);
	}
}
