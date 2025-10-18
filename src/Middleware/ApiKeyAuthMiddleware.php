<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Renderer\JsonRenderer;

/**
 * API Key Authentication Middleware.
 *
 * Validates API keys from the Authorization header and checks permissions.
 */
readonly class ApiKeyAuthMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ApiKeyFetcher $apiKeyFetcher,
		private JsonRenderer $jsonRenderer,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Get the Authorization header
		$authHeader = $request->getHeaderLine('Authorization');

		if ($authHeader === '') {
			return $this->unauthorizedResponse('API key required. Provide it in the Authorization header as "Bearer {key}"');
		}

		// Extract Bearer token
		if (!str_starts_with($authHeader, 'Bearer ')) {
			return $this->unauthorizedResponse('Invalid authorization format. Use "Bearer {key}"');
		}

		$apiKey = substr($authHeader, 7); // Remove "Bearer " prefix

		if ($apiKey === '') {
			return $this->unauthorizedResponse('API key cannot be empty');
		}

		// Validate the API key and check permissions
		$method = $request->getMethod();

		// Get the route path relative to the application base
		// For example: /rw_common/plugins/stacks/tcms/collections/blog becomes /collections/blog
		$basePath = $request->getAttribute('basePath', '');
		$fullPath = $request->getUri()->getPath();
		$path     = $basePath !== '' ? substr($fullPath, strlen((string)$basePath)) : $fullPath;

		$validatedKey = $this->apiKeyFetcher->validateKey($apiKey, $method, $path);

		if (!$validatedKey instanceof \TotalCMS\Domain\ApiKey\Data\ApiKeyData) {
			return $this->unauthorizedResponse('Invalid API key or insufficient permissions');
		}

		// API key is valid, add it to the request attributes for later use
		$request = $request->withAttribute('apiKey', $validatedKey);

		return $handler->handle($request);
	}

	private function unauthorizedResponse(string $message): ResponseInterface
	{
		return $this->jsonRenderer->json(
			$this->responseFactory->createResponse()->withStatus(401),
			['error' => ['message' => $message]]
		);
	}
}
