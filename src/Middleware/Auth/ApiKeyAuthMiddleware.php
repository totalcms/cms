<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Renderer\JsonRenderer;

/**
 * API Key Authentication Middleware.
 *
 * Validates API keys from the Authorization header and checks permissions.
 */
readonly class ApiKeyAuthMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ApiKeyAuthenticator $authenticator,
		private JsonRenderer $jsonRenderer,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Check if API key header is present
		if (!$this->authenticator->hasApiKeyHeader($request)) {
			return $this->unauthorizedResponse('API key required. Provide it in the Authorization header as "Bearer {key}" or in the X-API-Key header');
		}

		// Authenticate using API key
		$validatedKey = $this->authenticator->authenticate($request);

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
