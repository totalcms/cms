<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security;

use TotalCMS\Domain\Security\Encryption\SiteKey;

/**
 * Signed tokens this site makes and checks itself: unsubscribe links, order
 * links, upload grants.
 *
 *     $token   = $tokens->sign('unsubscribe', ['c' => 'contacts', 'id' => 'ann']);
 *     $payload = $tokens->verify('unsubscribe', $token); // the array, or null
 *
 * A token is `base64url(json({pur, exp?, d}))` + `.` + `base64url(hmac)`,
 * URL-safe without padding. It is bound to its purpose, so a token minted
 * for one link cannot open another, and it carries an optional expiry
 * (none: unsubscribe links in old emails keep working). The HMAC key is
 * derived from the site key, so tokens survive updates and travel with
 * push/pull, and no other install accepts them.
 *
 * The payload is signed, not encrypted: anyone holding a token can read it.
 * Remote signatures (license, marketplace) are RS256 through the license
 * keyring, not this class.
 */
final class SignedToken
{
	/** Longer tokens are refused before any decoding. */
	public const MAX_LENGTH = 4096;

	/** @var \Closure(): ?string */
	private \Closure $siteKey;

	/** @var \Closure(): int */
	private \Closure $now;

	/**
	 * @param (\Closure(): ?string)|null $siteKey the site key; defaults to SiteKey::get()
	 * @param (\Closure(): int)|null     $now     the clock; defaults to time()
	 */
	public function __construct(?\Closure $siteKey = null, ?\Closure $now = null)
	{
		$this->siteKey = $siteKey ?? SiteKey::get(...);
		$this->now     = $now ?? time(...);
	}

	/**
	 * @param array<string,mixed> $payload   returned as is by verify()
	 * @param int|null            $expiresAt Unix time after which the token is refused; null never expires
	 *
	 * @throws \RuntimeException when the site has no key to sign with
	 */
	public function sign(string $purpose, array $payload, ?int $expiresAt = null): string
	{
		$key = $this->key();
		if ($key === null) {
			throw new \RuntimeException('Cannot sign a token: this site has no site key yet.');
		}

		$envelope = ['pur' => $purpose];
		if ($expiresAt !== null) {
			$envelope['exp'] = $expiresAt;
		}
		$envelope['d'] = $payload;

		$body = self::encode(json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		return $body . '.' . self::encode(hash_hmac('sha256', $body, $key, true));
	}

	/**
	 * The payload of a token minted here for $purpose, or null for a bad
	 * signature, a malformed, oversize or expired token, or another purpose.
	 *
	 * @return array<string,mixed>|null
	 */
	public function verify(string $purpose, string $token): ?array
	{
		$key = $this->key();
		if ($key === null || $token === '' || strlen($token) > self::MAX_LENGTH || substr_count($token, '.') !== 1) {
			return null;
		}

		[$body, $signature] = explode('.', $token);
		if ($body === '' || !hash_equals(self::encode(hash_hmac('sha256', $body, $key, true)), $signature)) {
			return null;
		}

		$envelope = json_decode((string)self::decode($body), true);
		if (!is_array($envelope) || ($envelope['pur'] ?? null) !== $purpose || !is_array($envelope['d'] ?? null)) {
			return null;
		}

		if (isset($envelope['exp']) && (!is_int($envelope['exp']) || ($this->now)() > $envelope['exp'])) {
			return null;
		}

		/** @var array<string,mixed> */
		return $envelope['d'];
	}

	private function key(): ?string
	{
		$siteKey = ($this->siteKey)();

		return $siteKey === null || $siteKey === '' ? null : hash_hmac('sha256', 'signed-token', $siteKey, true);
	}

	private static function encode(string $bytes): string
	{
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}

	private static function decode(string $text): string|false
	{
		return base64_decode(strtr($text, '-_', '+/'), true);
	}
}
