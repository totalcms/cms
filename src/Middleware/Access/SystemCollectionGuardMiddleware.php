<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Refuses WRITES to code-executing system collections (e.g. `automations`,
 * whose handler is an external PHP file) through the generic object API unless
 * the caller is a super-admin operator.
 *
 * The admin UI for these collections is already AdminOnly-gated; this closes the
 * parallel generic-API path, where an access group with broad collection-write
 * could otherwise author an automation PHP handler and self-trigger it for RCE.
 *
 * No API-key / OAuth / public-submission bypass: a PHP-handler write is
 * RCE-grade and must require a real super-admin session, not just any trusted
 * credential. Reads are not gated here (that is a separate hardening). The
 * import/sync write paths (`/api/import/jumpstart`, `/api/sync/import`) reach
 * the object store through JumpStartImporter rather than this route, so they
 * enforce the same super-admin requirement themselves — see
 * JumpStartImporter::$allowSystemCollections and the two import/sync actions.
 *
 * Marked `readonly` (not `final`) so tests can override route-argument
 * resolution — mirroring the `BaseAccessMiddleware` testing approach.
 */
readonly class SystemCollectionGuardMiddleware implements MiddlewareInterface
{
	/** @var list<string> */
	private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

	public function __construct(
		private SessionInterface $session,
		private UserValidationService $userValidation,
		private JsonRenderer $jsonRenderer,
		private ResponseFactoryInterface $responseFactory,
		private Config $config,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Auth disabled globally = no security model; pass through (consistent
		// with BaseAccessMiddleware).
		if (($this->config->auth['enable'] ?? true) === false) {
			return $handler->handle($request);
		}

		// Only writes are gated; reads and the generic collection list are not.
		if (!in_array(strtoupper($request->getMethod()), self::WRITE_METHODS, true)) {
			return $handler->handle($request);
		}

		$collection = $this->resolveCollection($request);
		if (!in_array($collection, SchemaData::SYSTEM_COLLECTIONS, true)) {
			return $handler->handle($request);
		}

		$userId         = (string)($this->session->get(SessionKeys::AUTH_USER) ?? '');
		$userCollection = (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?? '');
		if ($userId !== '' && $this->userValidation->isSuperAdmin($userId, $userCollection)) {
			return $handler->handle($request);
		}

		return $this->jsonRenderer->json(
			$this->responseFactory->createResponse()->withStatus(403),
			['error' => ['message' => 'This collection can only be modified by an administrator.']],
		);
	}

	protected function resolveCollection(ServerRequestInterface $request): string
	{
		$route = RouteContext::fromRequest($request)->getRoute();
		if (!$route instanceof RouteInterface) {
			return '';
		}

		return $route->getArgument('collection') ?? '';
	}
}
