<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Security\SignedToken;

/**
 * Links to one order for a buyer who has no account: the page they land on
 * after paying, and the link in their receipt.
 *
 * A link names its order and carries a fingerprint of that order's secret,
 * signed with the site's key and good for a set time. It opens that order
 * and no other, and changing the order's secret ends every link made
 * before.
 */
final readonly class OrderAccess
{
	private const PURPOSE = 'commerce-order';

	public const THIRTY_DAYS = 2592000;

	public function __construct(
		private SignedToken $tokens,
		private OrderRepository $orders,
	) {
	}

	public function token(OrderData $order, int $ttlSeconds = self::THIRTY_DAYS): string
	{
		return $this->tokens->sign(self::PURPOSE, ['o' => $order->id(), 'h' => $this->fingerprint($order)], time() + $ttlSeconds);
	}

	/** The order, when $token is a live link to exactly it. */
	public function verify(string $orderId, string $token): ?OrderData
	{
		$payload = $token === '' ? null : $this->tokens->verify(self::PURPOSE, $token);
		if ($payload === null || ($payload['o'] ?? null) !== $orderId || !is_string($payload['h'] ?? null)) {
			return null;
		}

		$order = $this->orders->find($orderId);

		return $order instanceof OrderData && hash_equals($this->fingerprint($order), $payload['h']) ? $order : null;
	}

	private function fingerprint(OrderData $order): string
	{
		return substr(hash('sha256', $order->accessToken()), 0, 16);
	}
}
