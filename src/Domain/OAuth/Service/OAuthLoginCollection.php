<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Service;

use TotalCMS\Support\Config;

/**
 * Which auth collection signs in to authorize an OAuth client, from the
 * `oauth.loginCollection` setting.
 *
 * Unset, any signed-in user may authorize and a logged-out one is sent to the
 * default login page, as before. Set (totalcms.co sets `users`), a logged-out
 * caller is sent to that collection's login page, where staff from the
 * default collection can sign in too, and a session from any other auth
 * collection is treated as logged out.
 */
final readonly class OAuthLoginCollection
{
	public function __construct(
		private Config $config,
		private OAuthActivityLogger $activityLogger,
	) {
	}

	/**
	 * The configured collection, or '' when OAuth uses the default login.
	 */
	public function configured(): string
	{
		$collection = (string)($this->config->oauth['loginCollection'] ?? '');

		return $collection === $this->defaultCollection() ? '' : $collection;
	}

	/**
	 * Whether a session from $sessionCollection may authorize a client. A
	 * refusal is logged on the OAuth activity channel.
	 */
	public function accepts(string $sessionCollection, string $userId, string $clientId): bool
	{
		$configured = $this->configured();
		$collection = $sessionCollection !== '' ? $sessionCollection : $this->defaultCollection();
		if ($configured === '' || $collection === $configured || $collection === $this->defaultCollection()) {
			return true;
		}

		$this->activityLogger->loginCollectionRejected($clientId, $userId, $collection, $configured);

		return false;
	}

	/**
	 * Whether a login at $collection, returning to $returnUrl, may fall back to
	 * the default collection: only on the configured login page, and only
	 * when the login is part of an OAuth flow.
	 */
	public function fallsBackToDefault(string $collection, string $returnUrl): bool
	{
		$configured = $this->configured();

		return $configured !== '' && $collection === $configured && self::isAuthorizeUrl($returnUrl);
	}

	/**
	 * Whether $url is a pending `/oauth/authorize` request.
	 */
	public static function isAuthorizeUrl(string $url): bool
	{
		return str_contains((string)parse_url($url, PHP_URL_PATH), '/oauth/authorize');
	}

	private function defaultCollection(): string
	{
		return (string)($this->config->auth['collection'] ?? '');
	}
}
