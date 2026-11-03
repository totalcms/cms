<?php

declare(strict_types=1);

namespace TotalCMS\Renderer;

use Psr\Http\Message\ResponseInterface;

/**
 * Headers for a stored file sent back to a browser.
 *
 * Every file gets `nosniff`, so the browser keeps to the type we send. A
 * type that can carry script — HTML, XHTML, SVG, XML — also gets a sandbox
 * CSP: it still renders when opened directly, but its scripts do not run
 * and it gets a unique origin, never the site's. Without this an editor's
 * uploaded `.html` opened from `/stream/upload/…` or `/imageworks/upload/…`
 * ran as the CMS origin (security advisory GHSA-f354-xm96-5p3w,
 * 2026-11-03). An SVG shown through `<img>` is unaffected: images never
 * run script, and the CSP of an image response does not apply to the page.
 */
final class ActiveContentHeaders
{
	public const SANDBOX_POLICY = "sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:";

	/** @var list<string> */
	private const ACTIVE_TYPES = [
		'text/html',
		'application/xhtml+xml',
		'image/svg+xml',
		'text/xml',
		'application/xml',
	];

	public static function apply(ResponseInterface $response, string $mime): ResponseInterface
	{
		$response = $response->withHeader('X-Content-Type-Options', 'nosniff');

		return self::isActive($mime)
			? $response->withHeader('Content-Security-Policy', self::SANDBOX_POLICY)
			: $response;
	}

	/** Whether a browser would run script in a document of this type. */
	public static function isActive(string $mime): bool
	{
		$type = strtolower(trim(explode(';', $mime, 2)[0]));

		return in_array($type, self::ACTIVE_TYPES, true) || str_ends_with($type, '+xml');
	}
}
