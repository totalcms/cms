<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

use TotalCMS\Domain\Commerce\Data\AttentionDismissal;
use TotalCMS\Domain\Money\Money;

/**
 * A subscription, as it is stored: something a buyer pays for again every
 * period until it is canceled. A typed way to read it.
 *
 * It is never changed in place: {@see with()} gives a changed copy, and
 * {@see SubscriptionRepository::withLock()} is the one way to save one.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one reader per field of
 * @SuppressWarnings("PHPMD.ExcessivePublicCount") the subscription record,
 * @SuppressWarnings("PHPMD.TooManyMethods")       so no caller spells a key
 */
final readonly class SubscriptionData
{
	public const PENDING  = 'pending';
	public const TRIALING = 'trialing';
	public const ACTIVE   = 'active';
	public const PAST_DUE = 'past_due';
	public const PAUSED   = 'paused';
	public const CANCELED = 'canceled';

	/** The flag on one that was given up on before it began. */
	private const NEVER_BEGAN = 'neverBegan';

	/** The statuses in which the subscriber has what they pay for. A late payer keeps it while the provider retries the card. */
	public const LIVE = [self::TRIALING, self::ACTIVE, self::PAST_DUE];

	/** @param array<string,mixed> $data */
	public function __construct(private array $data)
	{
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return $this->data;
	}

	public function id(): string
	{
		return $this->text('id');
	}

	public function status(): string
	{
		return $this->text('status');
	}

	public function isLive(): bool
	{
		return in_array($this->status(), self::LIVE, true);
	}

	public function needsAttention(): string
	{
		return $this->text('needsAttention');
	}

	public function email(): string
	{
		return $this->text('email');
	}

	public function customerCollection(): string
	{
		return $this->text('customerCollection');
	}

	public function customerId(): string
	{
		return $this->text('customerId');
	}

	public function currency(): string
	{
		return $this->text('currency');
	}

	public function provider(): string
	{
		return $this->text('provider');
	}

	public function providerSubscriptionRef(): string
	{
		return $this->text('providerSubscriptionRef');
	}

	public function providerCustomerRef(): string
	{
		return $this->text('providerCustomerRef');
	}

	public function originOrderId(): string
	{
		return $this->text('originOrderId');
	}

	public function lastOrderId(): string
	{
		return $this->text('lastOrderId');
	}

	/** @return array<string,array<string,mixed>> the recurring lines, as they were at checkout */
	public function lines(): array
	{
		$lines = [];
		foreach ($this->map('lines') as $id => $line) {
			if (is_array($line)) {
				$lines[(string)$id] = $line;
			}
		}

		return $lines;
	}

	/** @return list<string> the access groups its lines give the subscriber */
	public function grantedGroups(): array
	{
		$groups = [];
		foreach ($this->lines() as $line) {
			$group = $line['accessGroup'] ?? '';
			if (is_string($group) && $group !== '') {
				$groups[$group] = true;
			}
		}

		return array_keys($groups);
	}

	public function interval(): string
	{
		return $this->text('interval');
	}

	public function intervalCount(): int
	{
		return max(1, $this->whole('intervalCount'));
	}

	/** The free days before the first payment, as its lines had them at checkout. */
	public function trialDays(): int
	{
		$days = 0;
		foreach ($this->lines() as $line) {
			$days = max($days, is_numeric($line['trialDays'] ?? null) ? (int)$line['trialDays'] : 0);
		}

		return $days;
	}

	public function recurringSubtotal(): int
	{
		return $this->whole('recurringSubtotal');
	}

	public function recurringDiscount(): int
	{
		return $this->whole('recurringDiscount');
	}

	public function recurringTax(): int
	{
		return $this->whole('recurringTax');
	}

	/** What the provider charges each period, in minor units. */
	public function recurringTotal(): int
	{
		return $this->whole('recurringTotal');
	}

	public function recurringTotalMoney(): Money
	{
		return Money::of($this->recurringTotal(), $this->currency());
	}

	public function taxMode(): string
	{
		return $this->text('taxMode');
	}

	/** @return list<array<string,mixed>> */
	public function taxLines(): array
	{
		return array_values(array_filter($this->map('taxLines'), is_array(...)));
	}

	/** @return array<string,mixed> */
	public function billingAddress(): array
	{
		return $this->strings('billingAddress');
	}

	public function discountCode(): string
	{
		return $this->text('discountCode');
	}

	/** @return array<string,mixed> */
	public function attributes(): array
	{
		return $this->strings('attributes');
	}

	/** @return array<string,mixed> */
	public function metadata(): array
	{
		return $this->strings('metadata');
	}

	public function trialEndsAt(): string
	{
		return $this->text('trialEndsAt');
	}

	public function currentPeriodEnd(): string
	{
		return $this->text('currentPeriodEnd');
	}

	public function cancelAtPeriodEnd(): bool
	{
		return ($this->data['cancelAtPeriodEnd'] ?? false) === true;
	}

	public function canceledAt(): string
	{
		return $this->text('canceledAt');
	}

	public function renewalCount(): int
	{
		return $this->whole('renewalCount');
	}

	public function lastFailedInvoiceRef(): string
	{
		return $this->text('lastFailedInvoiceRef');
	}

	public function accessToken(): string
	{
		return $this->text('accessToken');
	}

	public function created(): string
	{
		return $this->text('created');
	}

	/** @return array<string,mixed> */
	public function flags(): array
	{
		return $this->strings('flags');
	}

	public function hasFlag(string $flag): bool
	{
		return ($this->flags()[$flag] ?? false) === true;
	}

	public function withFlag(string $flag): self
	{
		return $this->with(['flags' => [$flag => true] + $this->flags()]);
	}

	/**
	 * The last time staff marked this subscription as handled: why it had
	 * needed someone, who said it was dealt with, and when. Empty when nobody has.
	 *
	 * @return array{reason: string, by: string, at: string}|array{}
	 */
	public function attentionDismissed(): array
	{
		return AttentionDismissal::from($this->flags());
	}

	/** The same subscription, no longer asking for someone, with why it had and who dealt with it kept. */
	public function withAttentionDismissed(string $reason, string $by): self
	{
		return $this->with(['needsAttention' => '', 'flags' => AttentionDismissal::onto($this->flags(), $reason, $by)]);
	}

	/**
	 * Whether it was given up on before it ever began: the order that was
	 * to start it expired, or its checkout could not be started. Such a
	 * one can still begin, if that order turns out to have been paid.
	 */
	public function neverBegan(): bool
	{
		return $this->status() === self::CANCELED && $this->hasFlag(self::NEVER_BEGAN);
	}

	/**
	 * The fields that give up on one that has not begun.
	 *
	 * @return array<string,mixed>
	 */
	public function givenUp(string $at): array
	{
		return ['status' => self::CANCELED, 'canceledAt' => $at, 'flags' => [self::NEVER_BEGAN => true] + $this->flags()];
	}

	/**
	 * Its flags, without the one that says it never began.
	 *
	 * @return array<string,mixed>
	 */
	public function flagsOnceBegun(): array
	{
		$flags = $this->flags();
		unset($flags[self::NEVER_BEGAN]);

		return $flags;
	}

	/** @param array<string,mixed> $changes */
	public function with(array $changes): self
	{
		return new self($changes + $this->data);
	}

	/**
	 * The subscription as its subscriber may see it: no secret, no
	 * provider reference, nothing the store keeps for itself.
	 *
	 * @return array<string,mixed>
	 */
	public function forSubscriber(): array
	{
		return array_diff_key($this->data, array_flip(['accessToken', 'flags', 'metadata', 'providerCustomerRef', 'providerSubscriptionRef', 'lastFailedInvoiceRef']));
	}

	private function text(string $key): string
	{
		$value = $this->data[$key] ?? '';

		return is_scalar($value) ? (string)$value : '';
	}

	private function whole(string $key): int
	{
		$value = $this->data[$key] ?? 0;

		return is_numeric($value) ? (int)$value : 0;
	}

	/** @return array<array-key,mixed> */
	private function map(string $key): array
	{
		$value = $this->data[$key] ?? [];

		return is_array($value) ? $value : [];
	}

	/** @return array<string,mixed> */
	private function strings(string $key): array
	{
		$kept = [];
		foreach ($this->map($key) as $name => $value) {
			$kept[(string)$name] = $value;
		}

		return $kept;
	}
}
