<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * The site's origin (scheme + domain) as web requests saw it, kept in a file
 * so the CLI can use it.
 *
 * The domain is auto-detected from the request Host header, and the CLI has
 * neither that nor SERVER_NAME, so it used to run as the literal 'unknown':
 * the license API had no record for it, and `tcms info` reported a licensed
 * Pro site as an invalid trial. Web requests now record their origin (same
 * mechanism as the `.docroot` file next to it) and the CLI reads it back.
 * An operator who needs something else sets `domain` in config/tcms.php,
 * which overrides both.
 */
final class SiteOrigin
{
	public static function remember(string $file, string $domain, bool $https): void
	{
		if ($domain === '' || $domain === 'unknown' || Config::isNonRoutableHost($domain)) {
			return;
		}

		$origin = ($https ? 'https://' : 'http://') . $domain;

		// The latest routable domain wins (a site moved from staging to its
		// live domain follows along), but an unchanged origin costs no write
		if (is_file($file) && @file_get_contents($file) === $origin) {
			return;
		}

		@file_put_contents($file, $origin);
	}

	/**
	 * @return array{domain: string, https: bool}|null
	 */
	public static function recall(string $file): ?array
	{
		if (!is_file($file)) {
			return null;
		}

		$origin = trim((string)@file_get_contents($file));
		$parts  = parse_url($origin);
		if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
			return null;
		}

		$domain = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

		return [
			'domain' => $domain,
			'https'  => ($parts['scheme'] ?? 'http') === 'https',
		];
	}
}
