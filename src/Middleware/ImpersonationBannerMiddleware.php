<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use TotalCMS\Domain\Auth\Service\ImpersonationServiceInterface;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Injects a fixed-position "Return to your account" banner into HTML responses
 * while a super-admin is impersonating another user.
 *
 * The banner is inline-styled so it renders correctly on both the admin
 * interface and unstyled author front-end pages. It contains a CSRF-protected
 * POST form targeting the stop-impersonation route and is base-path-aware so
 * it works on subpath installs.
 */
final readonly class ImpersonationBannerMiddleware implements MiddlewareInterface
{
	/**
	 * @param App<\Psr\Container\ContainerInterface> $app
	 */
	public function __construct(
		private ImpersonationServiceInterface $impersonation,
		private SessionInterface $session,
		private CSRFTokenManager $csrfTokenManager,
		private App $app,
	) {
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		$response = $handler->handle($request);

		if (!$this->impersonation->isImpersonating()) {
			return $response;
		}

		// Only inject into full HTML documents. Admin/Twig responses frequently leave
		// Content-Type unset (PHP's default_mimetype supplies text/html at emit time),
		// so accept an explicit text/html type OR an unset type — then confirm it's a
		// full document by anchoring on </body>, which also skips HTMX partials.
		$contentType = $response->getHeaderLine('Content-Type');
		if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
			return $response;
		}

		$body = (string)$response->getBody();
		if (!str_contains($body, '</body>')) {
			$response->getBody()->rewind();

			return $response;
		}

		$userId   = (string)($this->session->get(SessionKeys::AUTH_USER) ?? '');
		$injected = $this->inject($body, $this->buildBanner($userId));

		return $response->withBody((new Psr17Factory())->createStream($injected));
	}

	private function inject(string $body, string $banner): string
	{
		$pos = strripos($body, '</body>');

		if ($pos === false) {
			return $body . $banner;
		}

		return substr($body, 0, $pos) . $banner . substr($body, $pos);
	}

	private function buildBanner(string $userId): string
	{
		$basePath   = $this->app->getBasePath();
		$stopUrl    = $basePath . '/admin/impersonate/stop';
		$tokenField = $this->csrfTokenManager->getTokenField();
		$label      = $userId !== '' ? htmlspecialchars($userId, ENT_QUOTES, 'UTF-8') : 'another user';

		return <<<HTML
<div class="impersonation-banner" style="position:fixed;bottom:0;right:0;max-width:100%;z-index:99999;background:oklch(var(--totalform-text-color, 17% 0 0));color:oklch(var(--totalform-background, 100% 0 0));font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:14px;padding:10px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 -2px 8px rgba(0,0,0,0.4);border-radius:50px;margin:0.75rem">
<span style="display:flex;align-items:center;gap:8px;">
<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
Impersonating <strong style="margin-left:4px;">{$label}</strong>
</span>
<form method="post" action="{$stopUrl}" style="margin:0;padding:0;">
{$tokenField}
<button type="submit" style="background:oklch(var(--totalform-background, 100% 0 0));color:oklch(var(--totalform-text-color, 17% 0 0));border:none;border-radius:4px;padding:6px 14px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;">Return to your account</button>
</form>
</div>
HTML;
	}
}
