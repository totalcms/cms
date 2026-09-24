<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Service;

/**
 * Describes where an authorization code will be sent, for the consent screen.
 *
 * A self-registered client picks its own name, so "Claude wants access" proves
 * nothing — anyone can register a client called Claude with a redirect URI on
 * their own server. The redirect target is the part an attacker can't fake:
 * showing it (and flagging hosts no known AI client uses) is what makes
 * dynamic registration safe to leave on.
 *
 * Kinds:
 *   - known   — a hosted AI client's callback domain (claude.ai, chatgpt.com, …)
 *   - local   — loopback or a custom app scheme (cursor://, vscode://): the
 *               code goes to an app on the operator's own machine
 *   - unknown — any other web host
 */
final readonly class OAuthRedirectClassifier
{
	/**
	 * Callback domains of hosted AI clients. Subdomains match too.
	 */
	public const KNOWN_HOSTS = [
		'claude.ai',
		'claude.com',
		'anthropic.com',
		'chatgpt.com',
		'openai.com',
		'vscode.dev',
		'cursor.com',
	];

	private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

	/**
	 * @return array{host: string, kind: 'known'|'local'|'unknown'}
	 */
	public function classify(string $redirectUri): array
	{
		$scheme = strtolower((string)parse_url($redirectUri, PHP_URL_SCHEME));
		$host   = strtolower((string)parse_url($redirectUri, PHP_URL_HOST));

		if (!in_array($scheme, ['', 'http', 'https'], true)) {
			return ['host' => $scheme . '://', 'kind' => 'local'];
		}

		if ($host === '') {
			return ['host' => $redirectUri, 'kind' => 'unknown'];
		}

		if (in_array($host, self::LOOPBACK_HOSTS, true)) {
			return ['host' => $host, 'kind' => 'local'];
		}

		foreach (self::KNOWN_HOSTS as $known) {
			if ($host === $known || str_ends_with($host, '.' . $known)) {
				return ['host' => $host, 'kind' => 'known'];
			}
		}

		return ['host' => $host, 'kind' => 'unknown'];
	}
}
