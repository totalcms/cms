<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\PageMiddleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\LoginUrl;
use TotalCMS\Domain\Builder\Data\PageData;

/**
 * Core `auth` page middleware — gates a builder page behind a logged-in
 * session, optionally narrowed to one or more access groups.
 *
 * Two-tier check:
 *   1. **Not logged in** → 302 redirect to login. The original URL is
 *      preserved as `?redirect=` so the visitor lands back on the gated page
 *      after signing in. The page's `accessCollection` picks which auth
 *      collection they sign in to (see LoginUrl).
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
		private LoginUrl $loginUrl,
	) {
	}

	public function handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface
	{
		// Stage 1: not logged in at all → kick to login.
		if (!$this->accessManager->sessionHasUser()) {
			return $this->redirectToLogin($request, $page);
		}

		// Stage 2: logged in. If the page restricts to specific groups,
		// validate. Empty groups means "any login passes."
		if ($page->accessGroups !== [] && !$this->accessManager->userHasAccess($page->accessGroups)) {
			return $this->plainForbidden();
		}

		return null;
	}

	private function redirectToLogin(ServerRequestInterface $request, PageData $page): ResponseInterface
	{
		// Bring the visitor back to the page they were trying to reach.
		$loginUrl = $this->loginUrl->forAccessCollection($page->accessCollection, (string)$request->getUri());

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
}
