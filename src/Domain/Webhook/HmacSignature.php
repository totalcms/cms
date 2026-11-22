<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Webhook;

/**
 * Checks an HMAC signature over the exact bytes of a webhook body, in
 * constant time.
 */
final class HmacSignature
{
	/**
	 * @param string $rawBody   the body exactly as it was sent ({@see RawBody})
	 * @param string $secret    the shared secret; an empty one matches nothing
	 * @param string $signature what the sender sent
	 * @param string $algo      a hash_hmac() algorithm
	 * @param string $encoding  how the signature is written: `hex` or `base64`
	 */
	public static function matches(string $rawBody, string $secret, string $signature, string $algo = 'sha256', string $encoding = 'hex'): bool
	{
		// A signature made with no secret is one anyone can make.
		if ($secret === '' || $signature === '' || !in_array($algo, hash_hmac_algos(), true)) {
			return false;
		}

		$expected = hash_hmac($algo, $rawBody, $secret, true);
		$given    = match ($encoding) {
			'hex'    => ctype_xdigit($signature) && strlen($signature) % 2 === 0 ? hex2bin($signature) : false,
			'base64' => base64_decode($signature, true),
			default  => false,
		};

		return is_string($given) && hash_equals($expected, $given);
	}
}
