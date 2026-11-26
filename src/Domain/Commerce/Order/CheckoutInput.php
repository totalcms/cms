<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Exception\CommerceException;

/**
 * What a buyer may say at checkout: how they pay, who they are, where it
 * goes. There is no price here, and nothing else in the request is read.
 */
final readonly class CheckoutInput
{
	private const LONGEST_ATTRIBUTE = 200;

	/** @param array<string,string> $attributes */
	public function __construct(
		public string $provider,
		public string $email,
		public ?Address $shipping = null,
		public ?Address $billing = null,
		public ?string $shippingRateId = null,
		public array $attributes = [],
	) {
	}

	/**
	 * @param array<mixed>  $body
	 * @param list<string>  $allowedAttributes the names `commerce.checkoutAttributes` allows
	 *
	 * @throws CommerceException for an address whose country is not a two-letter code
	 */
	public static function fromRequest(array $body, array $allowedAttributes): self
	{
		$text     = static fn (string $key): string => is_string($body[$key] ?? null) ? trim($body[$key]) : '';
		$shipping = self::address($body['shippingAddress'] ?? null);
		$rateId   = $text('shippingRateId');

		return new self(
			provider: $text('provider'),
			email: $text('email'),
			shipping: $shipping,
			billing: self::address($body['billingAddress'] ?? null) ?? $shipping,
			shippingRateId: $rateId === '' ? null : $rateId,
			attributes: self::attributes($body['attributes'] ?? null, $allowedAttributes),
		);
	}

	private static function address(mixed $in): ?Address
	{
		if (!is_array($in)) {
			return null;
		}
		$address = Address::fromInput($in);

		return $address->isEmpty() ? null : $address;
	}

	/**
	 * @param list<string> $allowed
	 *
	 * @return array<string,string>
	 */
	private static function attributes(mixed $in, array $allowed): array
	{
		$kept = [];
		foreach (is_array($in) ? $in : [] as $key => $value) {
			if (!is_string($key) || !is_string($value) || !in_array($key, $allowed, true)) {
				continue;
			}
			$value = mb_substr(trim($value), 0, self::LONGEST_ATTRIBUTE);
			if ($value !== '') {
				$kept[$key] = $value;
			}
		}

		return $kept;
	}
}
