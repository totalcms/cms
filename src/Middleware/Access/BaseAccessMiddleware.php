<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Base Access Middleware.
 *
 * Provides common access control logic for all resource-specific middleware.
 * Enforces access group permissions with HTML/JSON error responses.
 * API keys bypass access group checks (trust model).
 */
abstract readonly class BaseAccessMiddleware implements MiddlewareInterface
{
	/**
	 * Resource name for error messages (e.g., 'collection', 'schema', 'template').
	 * Override in concrete classes.
	 */
	protected const RESOURCE_NAME = 'resource';

	public function __construct(
		protected UserValidationService $userValidation,
		protected AccessControlService $accessControl,
		protected PhpSession $session,
		protected JsonRenderer $jsonRenderer,
		protected TwigRenderer $twigRenderer,
		protected ResponseFactoryInterface $responseFactory,
		protected Config $config,
		protected OperationDetector $operationDetector,
		protected LoggerFactory $loggerFactory,
	) {
	}

	/** @SuppressWarnings("PHPMD.ElseExpression") */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// If auth is disabled globally, allow through
		if ($this->config->auth['enable'] === false) {
			return $handler->handle($request);
		}

		// Public submissions bypass access control (already validated by DualAuthMiddleware)
		if ($request->getAttribute('publicSubmission') === true) {
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

		// Super admins bypass all access checks
		if ($this->userValidation->isSuperAdmin($userId)) {
			return $handler->handle($request);
		}

		// Detect CRUD operation for permission checking
		$operation = $this->operationDetector->detectOperation($request);
		if (!$operation) {
			// Unable to detect operation, deny access and log in dev/debug mode
			if ($this->config->env === 'dev' || $this->config->debug) {
				$logger       = $this->loggerFactory->addFileHandler('totalcms-access.log')->createLogger('access');
				$routeContext = \Slim\Routing\RouteContext::fromRequest($request);
				$route        = $routeContext->getRoute();
				$routeName    = $route instanceof \Slim\Interfaces\RouteInterface ? $route->getName() : 'unknown';

				$logger->warning('Operation detection failed', [
					'resource'   => static::RESOURCE_NAME,
					'route_name' => $routeName,
					'path'       => $request->getUri()->getPath(),
					'method'     => $request->getMethod(),
					'user_id'    => $userId,
				]);
			}

			return $this->forbiddenResponse($request, $this->getErrorMessage());
		}

		// Check resource-specific permissions (implemented by concrete classes)
		$hasAccess = $this->checkPermission($userId, $operation, $request);

		if ($hasAccess === false) {
			return $this->forbiddenResponse($request, $this->getErrorMessage());
		}

		return $handler->handle($request);
	}

	/**
	 * Check if the user has permission to access the requested resource.
	 * Implemented by concrete middleware classes.
	 *
	 * @param string $userId User ID from session
	 * @param string $operation CRUD operation (create, read, update, delete)
	 * @param ServerRequestInterface $request HTTP request
	 *
	 * @return bool True if access allowed, false otherwise
	 */
	abstract protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool;

	/**
	 * Return a 403 Forbidden response (JSON for API, HTML for admin UI).
	 */
	protected function forbiddenResponse(ServerRequestInterface $request, string $message): ResponseInterface
	{
		$path = $request->getUri()->getPath();

		// Admin UI requests should get HTML response
		if (str_starts_with($path, '/admin/')) {
			$details = $this->config->env === 'dev'
				? sprintf("Path: %s\nMethod: %s\nUser: %s", $path, $request->getMethod(), $this->session->get(SessionKeys::AUTH_USER) ?? 'none')
				: null;

			return $this->twigRenderer->template(
				$this->responseFactory->createResponse()->withStatus(403),
				'access-denied.twig',
				[
					'message'  => $message,
					'details'  => $details,
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
	protected function getErrorMessage(): string
	{
		$isDev = $this->config->env === 'dev';

		return $isDev
			? sprintf('Access denied: Your access groups do not have permission to perform this action on this %s', static::RESOURCE_NAME)
			: 'Access denied';
	}
}
