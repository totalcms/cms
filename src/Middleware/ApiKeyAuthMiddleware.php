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
use TotalCMS\Support\Config;

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
		private Config $config,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Try Authorization: Bearer first (standard)
		$authHeader = $request->getHeaderLine('Authorization');
		$apiKey     = '';

		if ($authHeader !== '' && str_starts_with($authHeader, 'Bearer ')) {
			$apiKey = substr($authHeader, 7); // Remove "Bearer " prefix
		}

		// Fallback to X-API-Key header if no valid Bearer token (convenience)
		if ($apiKey === '' && $request->hasHeader('X-API-Key')) {
			$apiKey = $request->getHeaderLine('X-API-Key');
		}

		// No API key found in either header
		if ($apiKey === '') {
			return $this->unauthorizedResponse('API key required. Provide it in the Authorization header as "Bearer {key}" or in the X-API-Key header');
		}

		// Validate the API key and check permissions
		$method = $request->getMethod();

		// Get the route path by stripping the API base path from the full URL
		// Config->api contains the full URL (e.g., "https://demo.totalcms.test/rw_common/plugins/stacks/tcms")
		// We parse it to get just the path part, then strip that from the request path
		$fullPath = $request->getUri()->getPath();
		$path     = $fullPath;

		// Parse the API URL to get just the path portion
		$parsedApi = parse_url($this->config->api);
		if (isset($parsedApi['path']) && $parsedApi['path'] !== '') {
			$apiPath = rtrim((string)$parsedApi['path'], '/');
			// Strip the API base path from the request path
			if (str_starts_with($fullPath, $apiPath)) {
				$path = substr($fullPath, strlen($apiPath));
			}
		}

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
