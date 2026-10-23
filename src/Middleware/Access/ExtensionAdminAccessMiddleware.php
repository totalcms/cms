<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\AccessDenials;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Extension\Data\ExtensionRoute;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Extension Admin Access Middleware.
 *
 * Enforces the per-route permission on extension admin pages
 * (/admin/ext/{vendor}/{name}/{path}). Routes default to 'admin' (super
 * admins only) unless registered with permission: 'any'. Before this
 * middleware existed, any logged-in dashboard user could reach any
 * extension admin page by URL — the AdminNavItem permission only hid
 * the link.
 *
 * Disabled extensions and unmatched paths pass through: the dispatch
 * action returns its own 404, and answering 403 here would leak which
 * routes exist.
 */
readonly class ExtensionAdminAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'extension page';

	public function __construct(
		ActorResolver $actorResolver,
		ActorContext $actors,
		JsonRenderer $jsonRenderer,
		TwigRenderer $twigRenderer,
		ResponseFactoryInterface $responseFactory,
		Config $config,
		OperationDetector $operationDetector,
		LoggerFactory $loggerFactory,
		AccessDenials $denials,
		private ExtensionManager $extensionManager,
	) {
		parent::__construct(
			$actorResolver,
			$actors,
			$jsonRenderer,
			$twigRenderer,
			$responseFactory,
			$config,
			$operationDetector,
			$loggerFactory,
			$denials,
		);
	}

	/**
	 * Super admins bypass in the base class — reaching here means the user
	 * is NOT an admin, so only routes explicitly registered with
	 * permission: 'any' pass — and only when the user's access group grants
	 * this extension (the `extensions` block; missing block = all granted).
	 */
	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		$extensionId = $this->requestedExtensionId($request);
		$route       = $this->matchedExtensionRoute($request, $extensionId);

		// Unknown extension or path — let the dispatcher 404 it.
		if (!$route instanceof ExtensionRoute) {
			return true;
		}

		if ($route->permission !== 'any') {
			return false;
		}

		return $actor->authority()->canExtension($extensionId);
	}

	/**
	 * The dispatcher's catch-all route name isn't in OperationDetector's
	 * lists, so map the HTTP method directly. The operation isn't used by
	 * checkPermission() — this only keeps the base class from denying on
	 * detection failure.
	 */
	protected function detectOperation(ServerRequestInterface $request): ?string
	{
		return match (strtoupper($request->getMethod())) {
			'POST'            => 'create',
			'PUT', 'PATCH'    => 'update',
			'DELETE'          => 'delete',
			default           => 'read',
		};
	}

	private function requestedExtensionId(ServerRequestInterface $request): string
	{
		$args = $this->routeArguments($request);

		return ($args['vendor'] ?? '') . '/' . ($args['name'] ?? '');
	}

	private function matchedExtensionRoute(ServerRequestInterface $request, string $extensionId): ?ExtensionRoute
	{
		if (!$this->extensionManager->isEnabled($extensionId)) {
			return null;
		}

		$args = $this->routeArguments($request);

		return $this->extensionManager->matchExtensionAdminRoute(
			$extensionId,
			strtoupper($request->getMethod()),
			'/' . ltrim($args['path'] ?? '', '/'),
		);
	}

	/** @return array<string,string> */
	private function routeArguments(ServerRequestInterface $request): array
	{
		$route = RouteContext::fromRequest($request)->getRoute();

		return $route instanceof RouteInterface ? $route->getArguments() : [];
	}
}
