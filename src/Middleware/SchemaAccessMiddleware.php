<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Schema Access Middleware.
 *
 * Enforces access group permissions for schema operations.
 * API keys bypass access group checks (trust model).
 */
readonly class SchemaAccessMiddleware implements MiddlewareInterface
{
	public function __construct(
		private AccessControlService $accessControl,
		private PhpSession $session,
		private JsonRenderer $jsonRenderer,
		private TwigRenderer $twigRenderer,
		private ResponseFactoryInterface $responseFactory,
		private Config $config,
	) {
	}

	/** @SuppressWarnings("PHPMD.ElseExpression") */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// If auth is disabled globally, allow through
		if ($this->config->auth['enable'] === false) {
			return $handler->handle($request);
		}

		// API keys bypass group checks (trust model)
		$authMethod = $request->getAttribute('authMethod');
		if ($authMethod === 'apikey') {
			return $handler->handle($request);
		}

		// Get user ID from session
		$userId = $this->session->get(SessionKeys::AUTH_USER);
		if (!$userId) {
			return $this->forbiddenResponse($request, 'Authentication required');
		}

		// Get schema ID from route
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			// No route found, allow through (shouldn't happen)
			return $handler->handle($request);
		}

		$schema = $route->getArgument('schema');

		// Get HTTP method
		$method = $request->getMethod();

		// Check access permissions
		if ($schema) {
			// Specific schema - check access to that schema
			$hasAccess = $this->accessControl->canAccessSchema($userId, $schema, $method);
		} else {
			// No specific schema (e.g., GET /schemas, POST /schemas) - check general schema method permission
			$hasAccess = $this->accessControl->canAccessSchemasMethod($userId, $method);
		}

		if ($hasAccess === false) {
			return $this->forbiddenResponse($request, $this->getErrorMessage());
		}

		return $handler->handle($request);
	}

	/**
	 * Return a 403 Forbidden response (JSON for API, HTML for admin UI).
	 */
	private function forbiddenResponse(ServerRequestInterface $request, string $message): ResponseInterface
	{
		$path = $request->getUri()->getPath();

		// Admin UI requests should get HTML response
		if (str_starts_with($path, '/admin/')) {
			$details = $this->config->env === 'development'
				? sprintf("Path: %s\nMethod: %s\nUser: %s", $path, $request->getMethod(), $this->session->get(SessionKeys::AUTH_USER) ?? 'none')
				: null;

			return $this->twigRenderer->template(
				$this->responseFactory->createResponse()->withStatus(403),
				'access-denied.twig',
				[
					'message' => $message,
					'details' => $details,
					'referrer' => $request->getHeaderLine('Referer') ?: null,
				]
			);
		}

		// API requests get JSON response
		return $this->jsonRenderer->json(
			$this->responseFactory->createResponse()->withStatus(403),
			['error' => ['message' => $message]]
		);
	}

	/**
	 * Get error message based on environment.
	 */
	private function getErrorMessage(): string
	{
		$isDev = $this->config->env === 'development';

		return $isDev
			? 'Access denied: Your access groups do not have permission to perform this action on this schema'
			: 'Access denied';
	}
}
