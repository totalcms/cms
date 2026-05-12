<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\PageMiddleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Support\Config;

/**
 * Core `auth` page middleware — gates a builder page behind a logged-in
 * session, optionally narrowed to one or more access groups.
 *
 * Two-tier check:
 *   1. **Not logged in** → 302 redirect to admin login (HTML) or 401 JSON
 *      (API). The original URL is preserved as `?redirect=` so the visitor
 *      lands back on the gated page after signing in.
 *   2. **Logged in but `page.accessGroups` is set and the user isn't in any
 *      of those groups** → 403 forbidden. No login redirect — they're
 *      already logged in; sending them back to login would loop. SuperAdmins
 *      bypass the group check entirely (handled by AccessManager).
 *
 * Empty `page.accessGroups` means "any logged-in user passes" — the original
 * behavior, preserved as the default so existing pages don't change semantics
 * when the field is added.
 */
readonly class PageAuthMiddleware implements PageMiddlewareInterface
{
	public function __construct(
		private AccessManager $accessManager,
		private Config $config,
	) {
	}

	public function handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface
	{
		// Stage 1: not logged in at all → kick to login.
		if (!$this->accessManager->sessionHasUser()) {
			return $this->wantsJson($request)
				? $this->jsonError(401, 'Authentication required')
				: $this->redirectToLogin($request);
		}

		// Stage 2: logged in. If the page restricts to specific groups,
		// validate. Empty groups means "any login passes."
		if ($page->accessGroups !== [] && !$this->accessManager->userHasAccess($page->accessGroups)) {
			return $this->wantsJson($request)
				? $this->jsonError(403, 'Forbidden')
				: $this->plainForbidden();
		}

		return null;
	}

	private function wantsJson(ServerRequestInterface $request): bool
	{
		parse_str($request->getUri()->getQuery(), $query);
		if (($query['_format'] ?? '') === 'json') {
			return true;
		}

		$accept = strtolower($request->getHeaderLine('Accept'));

		return $accept !== '' && str_contains($accept, 'application/json');
	}

	private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
	{
		$loginUrl = rtrim($this->config->api, '/') . '/admin/login';

		// Bring the visitor back to the page they were trying to reach.
		$origin   = (string)$request->getUri();
		$loginUrl .= (str_contains($loginUrl, '?') ? '&' : '?')
			. http_build_query(['redirect' => $origin]);

		return (new Psr17Factory())->createResponse(302)
			->withHeader('Location', $loginUrl);
	}

	private function plainForbidden(): ResponseInterface
	{
		$response = (new Psr17Factory())->createResponse(403)
			->withHeader('Content-Type', 'text/plain; charset=utf-8');
		$response->getBody()->write('403 Forbidden');

		return $response;
	}

	private function jsonError(int $status, string $message): ResponseInterface
	{
		$factory  = new Psr17Factory();
		$response = $factory->createResponse($status)
			->withHeader('Content-Type', 'application/json; charset=utf-8');
		$response->getBody()->write((string)json_encode(['error' => $message]));

		return $response;
	}
}
