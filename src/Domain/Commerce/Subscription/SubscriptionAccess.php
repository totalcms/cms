<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

use TotalCMS\Domain\Security\SignedToken;
use TotalCMS\Support\Config;

/**
 * The signed link that opens one subscription's own pages, for a
 * subscriber who is not signed in: it is in their receipts and their
 * failed-payment notices.
 *
 * Like an order's link, it is tied to the subscription's own secret, so a
 * link cannot be made for another subscription, and changing the secret
 * ends every link there is.
 */
final readonly class SubscriptionAccess
{
	private const PURPOSE = 'commerce-subscription';

	public const ONE_YEAR = 31536000;

	public function __construct(
		private SignedToken $tokens,
		private SubscriptionRepository $subscriptions,
		private Config $config,
	) {
	}

	public function token(SubscriptionData $subscription, int $ttlSeconds = self::ONE_YEAR): string
	{
		return $this->tokens->sign(self::PURPOSE, ['s' => $subscription->id(), 'h' => $this->fingerprint($subscription)], time() + $ttlSeconds);
	}

	/** The subscription, when $token is a live link to exactly it. */
	public function verify(string $subscriptionId, string $token): ?SubscriptionData
	{
		$payload = $token === '' ? null : $this->tokens->verify(self::PURPOSE, $token);
		if ($payload === null || ($payload['s'] ?? null) !== $subscriptionId || !is_string($payload['h'] ?? null)) {
			return null;
		}

		$subscription = $this->subscriptions->find($subscriptionId);

		return $subscription instanceof SubscriptionData && hash_equals($this->fingerprint($subscription), $payload['h']) ? $subscription : null;
	}

	/** The signed address of the subscription's manage page. */
	public function url(SubscriptionData $subscription): string
	{
		return rtrim($this->config->url, '/') . rtrim($this->config->api, '/') . "/commerce/subscription/{$subscription->id()}?t=" . rawurlencode($this->token($subscription));
	}

	private function fingerprint(SubscriptionData $subscription): string
	{
		return substr(hash('sha256', $subscription->accessToken()), 0, 16);
	}
}
