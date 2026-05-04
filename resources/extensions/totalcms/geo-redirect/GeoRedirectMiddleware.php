<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\GeoRedirect;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface;

/**
 * Redirect visitors based on their country.
 *
 * The visitor's country comes from a CDN/proxy-injected request header.
 * We try the most common ones in order and use the first non-empty value:
 *
 *   1. CF-IPCountry         (Cloudflare)
 *   2. X-Country-Code       (generic / custom proxies)
 *   3. X-Vercel-IP-Country  (Vercel)
 *
 * No external IP-database lookup. If the reverse proxy doesn't inject one
 * of these headers, the middleware no-ops and the page renders normally.
 *
 * Per-page configuration lives in the page's `data` JSON blob:
 *
 *     {
 *       "geoRedirects": {
 *         "DE": "/de/about",
 *         "FR": "/fr/about",
 *         "*":  "/global/about"
 *       }
 *     }
 *
 * Country codes follow ISO 3166-1 alpha-2 (`US`, `DE`, `GB`, `JP`).
 * Lookup is case-insensitive — config is normalized to uppercase at read.
 *
 * `*` is a wildcard for "anything not explicitly listed" — useful for
 * compliance redirects ("send everyone except US to /eu").
 *
 * **Loop prevention:** if the request's path already equals the target
 * path, we don't redirect. Stops the obvious infinite loop without
 * needing a redirect counter or a "did I redirect already" cookie.
 *
 * **Failure modes** (all silent — geo-redirect breaking should never
 * break the live page):
 *   - No country header → no redirect, render normally
 *   - Country not in map AND no `*` → no redirect
 *   - Target equals current URL → no redirect (loop guard)
 *   - Malformed config → ignored entries, no fatal
 */
class GeoRedirectMiddleware implements PageMiddlewareInterface
{
	/**
	 * Headers tried in order. First non-empty wins. Order is not arbitrary:
	 * CF-IPCountry is the most ubiquitous, X-Country-Code is the convention
	 * for generic / DIY proxies, X-Vercel-IP-Country is Vercel-specific.
	 */
	private const COUNTRY_HEADERS = [
		'CF-IPCountry',
		'X-Country-Code',
		'X-Vercel-IP-Country',
	];

	public function handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface
	{
		$rules = $this->normalizeRules($page->data['geoRedirects'] ?? null);
		if ($rules === []) {
			return null;
		}

		$country = $this->resolveCountry($request);
		if ($country === null) {
			return null;
		}

		$target = $rules[$country] ?? $rules['*'] ?? null;
		if ($target === null || $target === '') {
			return null;
		}

		// Loop guard: if the visitor is already on the target path, no
		// point redirecting them to where they already are. Compare paths
		// only — preserve query strings on the original request.
		if ($this->pathMatches($request->getUri()->getPath(), $target)) {
			return null;
		}

		return (new Psr17Factory())->createResponse(302)
			->withHeader('Location', $target)
			// Help upstream caches keep variants separated.
			->withHeader('Vary', $this->varyHeader());
	}

	/**
	 * Normalize the page-data config into `array<string,string>` with
	 * upper-case country keys. Drops malformed entries silently — admin
	 * typos shouldn't crash the page.
	 *
	 * @return array<string,string>
	 */
	private function normalizeRules(mixed $raw): array
	{
		if (!is_array($raw)) {
			return [];
		}

		$rules = [];
		foreach ($raw as $country => $target) {
			if (!is_string($country) || !is_string($target)) {
				continue;
			}
			$country = strtoupper(trim($country));
			$target  = trim($target);
			if ($country === '' || $target === '') {
				continue;
			}
			$rules[$country] = $target;
		}

		return $rules;
	}

	/**
	 * Walk the known country headers in order, return the first non-empty
	 * uppercase value. Returns null when nothing useful is set — the
	 * common case for local dev or sites not behind a country-aware CDN.
	 */
	private function resolveCountry(ServerRequestInterface $request): ?string
	{
		foreach (self::COUNTRY_HEADERS as $name) {
			$value = trim($request->getHeaderLine($name));
			if ($value !== '') {
				return strtoupper($value);
			}
		}

		return null;
	}

	/**
	 * Compare the request path with the target's path component, ignoring
	 * query strings on either side. Targets can be relative paths, absolute
	 * paths, or full URLs — only the path portion matters for the loop guard.
	 */
	private function pathMatches(string $requestPath, string $target): bool
	{
		$targetPath = parse_url($target, PHP_URL_PATH);
		if (!is_string($targetPath)) {
			return false;
		}

		return rtrim($requestPath, '/') === rtrim($targetPath, '/');
	}

	private function varyHeader(): string
	{
		// Tell upstream caches that the response varies by country header
		// so per-country variants don't clobber each other.
		return implode(', ', self::COUNTRY_HEADERS);
	}
}
