<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

/**
 * What a subscription becomes after something happened to it, and what
 * must be done about that. The state machine answers with one; whoever
 * asked saves and acts.
 */
final readonly class SubscriptionTransition
{
	/**
	 * @param bool                     $applied whether anything changes
	 * @param string                   $reason  why nothing does, when it does not
	 * @param array<string,mixed>      $changes the fields to save beside the status
	 * @param list<SubscriptionEffect> $effects in the order they run
	 */
	public function __construct(
		public bool $applied,
		public string $reason,
		public string $status,
		public array $changes = [],
		public string $needsAttention = '',
		public array $effects = [],
	) {
	}

	/**
	 * @param array<string,mixed>      $changes
	 * @param list<SubscriptionEffect> $effects
	 */
	public static function to(SubscriptionData $subscription, string $status, array $changes = [], array $effects = [], ?string $needsAttention = null): self
	{
		return new self(true, '', $status, $changes, $needsAttention ?? $subscription->needsAttention(), $effects);
	}

	public static function ignored(SubscriptionData $subscription, string $reason): self
	{
		return new self(false, $reason, $subscription->status(), [], $subscription->needsAttention());
	}

	/** For an event about a subscription the store does not have. */
	public static function none(string $reason): self
	{
		return new self(false, $reason, '');
	}

	/**
	 * The fields this transition writes to the subscription.
	 *
	 * @return array<string,mixed>
	 */
	public function fields(): array
	{
		return ['status' => $this->status, 'needsAttention' => $this->needsAttention] + $this->changes;
	}
}
