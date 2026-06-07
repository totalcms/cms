<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\UserValidationService;
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
		UserValidationService $userValidation,
		AccessControlService $accessControl,
		SessionInterface $session,
		JsonRenderer $jsonRenderer,
		TwigRenderer $twigRenderer,
		ResponseFactoryInterface $responseFactory,
		Config $config,
		OperationDetector $operationDetector,
		LoggerFactory $loggerFactory,
		private ExtensionManager $extensionManager,
	) {
		parent::__construct(
			$userValidation,
			$accessControl,
			$session,
			$jsonRenderer,
			$twigRenderer,
			$responseFactory,
			$config,
			$operationDetector,
			$loggerFactory,
		);
	}

	/**
	 * Super admins bypass in the base class — reaching here means the user
	 * is NOT an admin, so only routes explicitly registered with
	 * permission: 'any' pass.
	 */
	protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
	{
		$route = $this->matchedExtensionRoute($request);

		// Unknown extension or path — let the dispatcher 404 it.
		if (!$route instanceof ExtensionRoute) {
			return true;
		}

		return $route->permission === 'any';
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

	private function matchedExtensionRoute(ServerRequestInterface $request): ?ExtensionRoute
	{
		$route = RouteContext::fromRequest($request)->getRoute();
		if (!$route instanceof RouteInterface) {
			return null;
		}

		$args        = $route->getArguments();
		$extensionId = ($args['vendor'] ?? '') . '/' . ($args['name'] ?? '');

		if (!$this->extensionManager->isEnabled($extensionId)) {
			return null;
		}

		return $this->extensionManager->matchExtensionAdminRoute(
			$extensionId,
			strtoupper($request->getMethod()),
			'/' . ltrim($args['path'] ?? '', '/'),
		);
	}
}
