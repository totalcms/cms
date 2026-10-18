<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security;

use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Domain\Security\Encryption\SiteKey;

/**
 * Honeypot and signed time trap for public forms.
 *
 * A form with `botProtection` carries two extra fields: an empty text input
 * a person never sees ({@see self::HONEYPOT_FIELD}) and a hidden token
 * ({@see self::TIMESTAMP_FIELD}) that records when the form was rendered,
 * signed with the per-site key. A submission fails when the honeypot holds
 * anything, when the token is missing or forged, when it arrives sooner than
 * a person could fill the form, or when the token is a day old.
 *
 * The signature covers the collection and the form type (`register` or
 * `create`), so a token issued for one form is useless on another.
 *
 * The key is derived from the same per-site secret {@see Cipher::encrypt()} uses, falling
 * back to Cipher::SALT only before the data directory exists — when there is
 * nothing to register into anyway.
 */
final readonly class BotProtection
{
	// Named to match no autofill profile (identity, address, payment), so a
	// browser or password manager never fills it for a person.
	public const HONEYPOT_FIELD  = 'extra_reference_code';
	public const TIMESTAMP_FIELD = 'form_ts';
	public const MIN_SECONDS     = 3;
	public const MAX_AGE_SECONDS = 86400;

	public const REASON_HONEYPOT      = 'honeypot';
	public const REASON_MISSING       = 'missing';
	public const REASON_BAD_SIGNATURE = 'bad_signature';
	public const REASON_TOO_FAST      = 'too_fast';
	public const REASON_EXPIRED       = 'expired';

	private string $key;

	public function __construct(?string $key = null)
	{
		// A subkey, so the token signature never uses the site key itself.
		$this->key = hash_hmac('sha256', 'bot-protection', $key ?? SiteKey::get() ?? Cipher::SALT);
	}

	/** Signed token "<unix>.<hmac>" for a form rendered now. */
	public function issue(string $collection, string $formType, ?int $now = null): string
	{
		$time = $now ?? time();

		return $time . '.' . $this->sign($time, $collection, $formType);
	}

	/**
	 * '' when the submission passes; otherwise the reason it failed.
	 *
	 * @param array<string,mixed> $body
	 */
	public function check(array $body, string $collection, string $formType, ?int $now = null): string
	{
		$honeypot = $body[self::HONEYPOT_FIELD] ?? '';
		if (!is_string($honeypot) || trim($honeypot) !== '') {
			return self::REASON_HONEYPOT;
		}

		$token = $body[self::TIMESTAMP_FIELD] ?? '';
		if (!is_string($token) || $token === '') {
			return self::REASON_MISSING;
		}

		if (preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $token, $parts) !== 1) {
			return self::REASON_BAD_SIGNATURE;
		}

		$issued = (int)$parts[1];
		if (!hash_equals($this->sign($issued, $collection, $formType), $parts[2])) {
			return self::REASON_BAD_SIGNATURE;
		}

		$age = ($now ?? time()) - $issued;
		if ($age < self::MIN_SECONDS) {
			return self::REASON_TOO_FAST;
		}
		if ($age > self::MAX_AGE_SECONDS) {
			return self::REASON_EXPIRED;
		}

		return '';
	}

	/**
	 * True when the body carries either bot field, even empty.
	 *
	 * @param array<string,mixed> $body
	 */
	public function present(array $body): bool
	{
		return array_key_exists(self::HONEYPOT_FIELD, $body) || array_key_exists(self::TIMESTAMP_FIELD, $body);
	}

	/**
	 * $body without the two bot fields.
	 *
	 * @param array<string,mixed> $body
	 *
	 * @return array<string,mixed>
	 */
	public function strip(array $body): array
	{
		unset($body[self::HONEYPOT_FIELD], $body[self::TIMESTAMP_FIELD]);

		return $body;
	}

	private function sign(int $time, string $collection, string $formType): string
	{
		return hash_hmac('sha256', "{$time}|{$collection}|{$formType}", $this->key);
	}
}
