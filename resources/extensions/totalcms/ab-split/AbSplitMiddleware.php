<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\AbSplit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * Renders an alternate page template at the same URL for a percentage of
 * visitors. The chosen variant is sticky per visitor (cookie-based) so
 * refreshes don't re-bucket — important so the visitor sees consistent
 * content across an analytics session.
 *
 * Per-page configuration lives in the page's `data` JSON blob:
 *
 *     {
 *       "abTemplate": "pages/contact-b.twig",  // alternate template path
 *       "abPercent" : 50                        // percent sent to variant B (default 50)
 *     }
 *
 * Cookie: `tcms_ab_<pageId>` set to `a` or `b`, 30-day TTL.
 *
 * Variant A is the page's normal render (return null → chain continues).
 * Variant B renders `abTemplate` with the same `page` context — same URL,
 * no redirect, no extra round-trip.
 */
class AbSplitMiddleware implements PageMiddlewareInterface
{
	public const COOKIE_PREFIX  = 'tcms_ab_';
	public const COOKIE_TTL     = 30 * 86400;
	public const DEFAULT_PERCENT = 50;

	public function __construct(private readonly TwigEngine $twigEngine)
	{
	}

	public function handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface
	{
		$altTemplate = $this->stringConfig($page, 'abTemplate');
		if ($altTemplate === '') {
			return null;
		}

		$percent = $this->percent($page);
		$variant = $this->bucket($request, $page->id, $percent);

		// Variant A → fall through to the page's normal render, but persist
		// the bucket via Set-Cookie so subsequent visits stick. We don't have
		// a Response to attach the header to (returning null lets the page
		// render normally), so emit it directly via PHP's header() — it's
		// additive with the renderer's response headers downstream.
		if ($variant === 'a') {
			$this->emitCookie($this->cookie($page->id, 'a'));

			return null;
		}

		// Variant B → render the alternate template at this URL. Same `page`
		// context so meta tags, og:image, page.data, etc. all carry over;
		// only the template body differs.
		try {
			$body = $this->twigEngine->render($altTemplate, [
				'page'   => $page->toArray(),
				'params' => $request->getQueryParams(),
			]);
		} catch (\Throwable) {
			// Alternate template failed (typo, missing file, Twig error).
			// Fall through to the normal page render rather than 500-ing the
			// site — A/B tests breaking should never break the live page.
			return null;
		}

		return (new Psr17Factory())
			->createResponse(200)
			->withHeader('Content-Type', 'text/html; charset=utf-8')
			->withHeader('Set-Cookie', $this->cookie($page->id, 'b'))
			->withBody($this->stream($body));
	}

	/**
	 * Read a string-typed config value from page.data. Returns '' for missing
	 * or non-string values — keeps the middleware tolerant of admin typos.
	 */
	private function stringConfig(PageData $page, string $key): string
	{
		$value = $page->data[$key] ?? '';

		return is_string($value) ? trim($value) : '';
	}

	/**
	 * Resolve the variant B percentage. Out-of-range or non-numeric values
	 * fall back to the 50/50 default. Clamped to 0..100.
	 */
	private function percent(PageData $page): int
	{
		$raw = $page->data['abPercent'] ?? null;
		if (!is_numeric($raw)) {
			return self::DEFAULT_PERCENT;
		}

		return max(0, min(100, (int)$raw));
	}

	/**
	 * Sticky bucket: existing cookie wins; otherwise weighted random per the
	 * configured percent. Cookie is keyed per page so the same visitor can be
	 * in different buckets on different pages.
	 */
	private function bucket(ServerRequestInterface $request, string $pageId, int $percentB): string
	{
		$cookies  = $request->getCookieParams();
		$existing = $cookies[self::COOKIE_PREFIX . $pageId] ?? null;
		if ($existing === 'a' || $existing === 'b') {
			return $existing;
		}

		// 0 / 100 mean nobody / everybody — explicit guards keep the random
		// call from being subtly off by one.
		if ($percentB <= 0) {
			return 'a';
		}
		if ($percentB >= 100) {
			return 'b';
		}

		return random_int(1, 100) <= $percentB ? 'b' : 'a';
	}

	private function cookie(string $pageId, string $variant): string
	{
		$expires = gmdate('D, d M Y H:i:s T', time() + self::COOKIE_TTL);

		return sprintf(
			'%s%s=%s; Expires=%s; Path=/; SameSite=Lax',
			self::COOKIE_PREFIX,
			$pageId,
			$variant,
			$expires,
		);
	}

	/**
	 * Emit a Set-Cookie header out-of-band. Used for variant A where we
	 * return null (to let the page render normally) and therefore have no
	 * Response to attach the cookie to. PHP's header() is additive with the
	 * response headers Slim emits later — multiple Set-Cookie headers are
	 * legal and stackable. Marked protected so tests can subclass + capture.
	 */
	protected function emitCookie(string $cookie): void
	{
		if (headers_sent()) {
			return;
		}
		header('Set-Cookie: ' . $cookie, replace: false);
	}

	private function stream(string $body): \Psr\Http\Message\StreamInterface
	{
		return (new Psr17Factory())->createStream($body);
	}
}
