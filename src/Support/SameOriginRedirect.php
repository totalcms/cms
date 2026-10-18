<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * The open-redirect guard shared by every action that forwards a visitor to
 * a target that arrived from outside: the post-login `redirect`, and the
 * `verifyRedirect` a registration form carries through the verification email.
 *
 * A target survives only when it stays on this site: a relative path with a
 * single leading slash (`//host` is scheme-relative and leaves the site), or
 * an absolute http(s) URL whose host matches the request host. Anything else
 * returns the fallback.
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
		if (is_array($parts)
			&& in_array($parts['scheme'] ?? '', ['http', 'https'], true)
			&& strcasecmp((string)($parts['host'] ?? ''), $requestHost) === 0
		) {
			return $candidate;
		}

		return $fallback;
	}
}
