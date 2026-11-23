<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Stripe;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Webhook\HmacSignature;
use TotalCMS\Domain\Webhook\RawBody;
use TotalCMS\Domain\Webhook\WebhookVerifier;

/**
 * Checks that a webhook came from Stripe.
 *
 * Stripe sends `Stripe-Signature: t=<unix time>,v1=<hex>[,v1=<hex>…]`. Each
 * `v1` is an HMAC-SHA256 of "<t>.<raw body>" made with the endpoint's
 * signing secret; there are two while a secret is being rolled, and one
 * match is enough. The time is part of what is signed, so an old event
 * cannot be sent again later: anything more than five minutes from now is
 * refused, as Stripe's own libraries do.
 */
final readonly class StripeWebhookVerifier implements WebhookVerifier
{
	public const TOLERANCE_SECONDS = 300;

	private const HEADER = 'Stripe-Signature';

	/** @param (\Closure(): int)|null $now the clock; the real one when null */
	public function __construct(
		private string $secret,
		private ?\Closure $now = null,
	) {
	}

	public function verify(ServerRequestInterface $request): bool
	{
		if ($this->secret === '') {
			return false;
		}

		$timestamp  = '';
		$signatures = [];
		foreach (explode(',', $request->getHeaderLine(self::HEADER)) as $part) {
			[$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
			if ($name === 't') {
				$timestamp = $value;
			} elseif ($name === 'v1') {
				$signatures[] = $value;
			}
		}

		if ($timestamp === '' || !ctype_digit($timestamp) || abs(($this->now instanceof \Closure ? ($this->now)() : time()) - (int)$timestamp) > self::TOLERANCE_SECONDS) {
			return false;
		}

		$signed = $timestamp . '.' . RawBody::of($request);
		foreach ($signatures as $signature) {
			if (HmacSignature::matches($signed, $this->secret, $signature)) {
				return true;
			}
		}

		return false;
	}
}
