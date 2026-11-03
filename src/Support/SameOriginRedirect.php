<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * The open-redirect guard shared by every action that forwards a visitor to
 * a target that arrived from outside: the post-login `redirect`, and the
 * `verifyRedirect` a registration form carries through the verification email.
 *
 * A target survives only when it stays on this site: a path with a single
 * leading slash (`//host` is scheme-relative and leaves the site), a
 * relative reference with no scheme and no host (`members.php`, `admin`),
 * or an absolute http(s) URL whose host matches the request host. Anything
 * else — `javascript:` included — returns the fallback.
 *
 * Surrounding whitespace is trimmed first. A backslash or a control character
 * anywhere is refused outright: browsers read `/\host` as `//host`, drop tabs
 * and newlines out of URLs, and a CR/LF in a Location header is a header
 * injection. No legitimate redirect needs any of them.
 */
final class SameOriginRedirect
{
	public static function resolve(string $candidate, string $requestHost, string $fallback): string
	{
		if (preg_match('/[\\\\\x00-\x1F\x7F]/', $candidate) === 1) {
			return $fallback;
		}

		$candidate = trim($candidate);

		if ($candidate === '') {
			return $fallback;
		}

		if (str_starts_with($candidate, '/') && !str_starts_with($candidate, '//')) {
			return $candidate;
		}

		$parts = parse_url($candidate);
		if (is_array($parts) && !isset($parts['scheme']) && !isset($parts['host'])) {
			// Relative reference: resolved against the current page, it stays
			// here — unless it carries an encoded slash or backslash, which a
			// later decode turns into `//host`.
			return preg_match('/%(2f|5c)/i', $candidate) === 1 ? $fallback : $candidate;
		}

		if (is_array($parts)
			&& in_array($parts['scheme'] ?? '', ['http', 'https'], true)
			&& strcasecmp((string)($parts['host'] ?? ''), $requestHost) === 0
		) {
			return $candidate;
		}

		return $fallback;
	}
}
