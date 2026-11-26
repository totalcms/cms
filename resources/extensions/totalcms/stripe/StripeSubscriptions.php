<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Stripe;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEventType;
use TotalCMS\Domain\Money\Money;

/**
 * Stripe Billing, as the store uses it: Stripe holds the schedule, charges
 * the card each period and tries a failed card again. This reads what
 * Stripe says about a subscription and its invoices, and carries the
 * store's three requests: cancel, where does it stand, and a page for the
 * buyer's card.
 *
 * Field paths are those of the API version {@see StripeClient::VERSION}
 * pins. An invoice names its subscription under `parent`, and its payment
 * under `payments`, which Stripe sends only when asked. A subscription's
 * period ends on its items.
 */
final readonly class StripeSubscriptions
{
	/** Stripe's words for a subscription's status, in the store's. One not here has not begun, and says nothing. */
	private const STATUS = [
		'trialing'           => 'trialing',
		'active'             => 'active',
		'past_due'           => 'past_due',
		'paused'             => 'paused',
		'canceled'           => 'canceled',
		'unpaid'             => 'canceled',
		'incomplete_expired' => 'canceled',
	];

	public function __construct(
		private StripeClient $client,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * An invoice was paid. Only one that renews a subscription is news: the
	 * first invoice is the checkout's own, and the checkout's event covers it.
	 *
	 * @param array<array-key,mixed> $invoice
	 *
	 * @throws ProviderException when the invoice's payment cannot be read; Stripe then sends the event again
	 */
	public function invoicePaid(string $eventId, array $invoice): ?SubscriptionEvent
	{
		$subscription = $this->subscriptionOf($invoice);
		if ($subscription === '' || $this->text($invoice, 'billing_reason') !== 'subscription_cycle') {
			return null;
		}

		$currency = strtoupper($this->text($invoice, 'currency'));
		$taxed    = is_array($invoice['automatic_tax'] ?? null) && ($invoice['automatic_tax']['enabled'] ?? false) === true;
		$tax      = $this->taxOf($invoice);
		// What the store asked for: the total, less the tax Stripe added on
		// top. Tax that is part of the store's own prices stays in.
		$before   = $this->int($invoice, 'total') - $this->taxOf($invoice, 'exclusive');

		return new SubscriptionEvent(
			provider: StripeProvider::ID,
			eventId: $eventId,
			type: SubscriptionEventType::Renewed,
			subscriptionId: $this->engineId($invoice),
			providerSubscriptionRef: $subscription,
			currentPeriodEnd: $this->periodEndOf($invoice),
			amount: Money::of($this->int($invoice, 'amount_paid'), $currency),
			providerInvoiceRef: $this->text($invoice, 'id'),
			providerPaymentRef: $this->paymentOf($invoice),
			// What Stripe worked out itself is told apart from what the store asked for.
			tax: $taxed ? Money::of($tax, $currency) : null,
			orderTotal: $taxed ? Money::of(max(0, $before), $currency) : null,
		);
	}

	/** @param array<array-key,mixed> $invoice */
	public function invoiceFailed(string $eventId, array $invoice): ?SubscriptionEvent
	{
		$subscription = $this->subscriptionOf($invoice);
		if ($subscription === '') {
			return null;
		}

		return new SubscriptionEvent(
			provider: StripeProvider::ID,
			eventId: $eventId,
			type: SubscriptionEventType::PaymentFailed,
			subscriptionId: $this->engineId($invoice),
			providerSubscriptionRef: $subscription,
			providerInvoiceRef: $this->text($invoice, 'id'),
		);
	}

	/**
	 * A subscription changed, or is asked where it stands.
	 *
	 * @param array<array-key,mixed> $subscription
	 */
	public function updated(string $eventId, array $subscription): ?SubscriptionEvent
	{
		$status = self::STATUS[$this->text($subscription, 'status')] ?? '';
		if ($status === '') {
			return null;
		}

		$items = is_array($subscription['items'] ?? null) && is_array($subscription['items']['data'] ?? null) ? array_values(array_filter($subscription['items']['data'], is_array(...))) : [];
		$end   = $items[0]['current_period_end'] ?? null;

		return new SubscriptionEvent(
			provider: StripeProvider::ID,
			eventId: $eventId,
			type: SubscriptionEventType::Updated,
			subscriptionId: $this->metadataId($subscription),
			providerSubscriptionRef: $this->text($subscription, 'id'),
			status: $status,
			currentPeriodEnd: is_int($end) ? $end : null,
			// Stripe's portal may name the day it ends instead of saying "at the end of the period".
			cancelAtPeriodEnd: ($subscription['cancel_at_period_end'] ?? false) === true || ($status !== 'canceled' && is_int($subscription['cancel_at'] ?? null)),
		);
	}

	/** @param array<array-key,mixed> $subscription */
	public function deleted(string $eventId, array $subscription): SubscriptionEvent
	{
		return new SubscriptionEvent(
			provider: StripeProvider::ID,
			eventId: $eventId,
			type: SubscriptionEventType::Canceled,
			subscriptionId: $this->metadataId($subscription),
			providerSubscriptionRef: $this->text($subscription, 'id'),
			status: 'canceled',
		);
	}

	/** @throws ProviderException */
	public function cancel(string $subscriptionRef, bool $atPeriodEnd): void
	{
		$path = '/subscriptions/' . rawurlencode($subscriptionRef);
		if ($atPeriodEnd) {
			$this->client->post($path, ['cancel_at_period_end' => true], 'cancel-' . $subscriptionRef);

			return;
		}

		$this->client->delete($path);
	}

	public function fetch(string $subscriptionRef): ?SubscriptionEvent
	{
		if ($subscriptionRef === '') {
			return null;
		}

		try {
			$subscription = $this->client->get('/subscriptions/' . rawurlencode($subscriptionRef));
		} catch (ProviderException) {
			return null;
		}

		// Not one of Stripe's event ids: a name of its own for "what the subscription says now".
		return $this->updated("subscription-{$subscriptionRef}-" . $this->text($subscription, 'status'), $subscription);
	}

	/** Stripe's own page where the buyer changes their card and sees their invoices. '' when Stripe gives none. */
	public function portalUrl(string $customerRef, string $returnUrl): string
	{
		if ($customerRef === '') {
			return '';
		}

		try {
			// A new page every time it is asked for: each is short-lived.
			$session = $this->client->post('/billing_portal/sessions', ['customer' => $customerRef, 'return_url' => $returnUrl], 'portal-' . bin2hex(random_bytes(12)));
		} catch (ProviderException $e) {
			$this->logger->warning('Stripe gave no billing page for a subscriber. Is the customer portal switched on in Stripe?', ['code' => $e->providerCode]);

			return '';
		}

		return $this->text($session, 'url');
	}

	/**
	 * The payment an invoice was paid with: what a refund is made against.
	 * Stripe lists an invoice's payments only when asked, so an invoice
	 * that came without them is asked for again. '' for an invoice nothing
	 * was charged for.
	 *
	 * @param array<array-key,mixed>|string $invoice an invoice, or the id of one
	 *
	 * @throws ProviderException when Stripe cannot be asked
	 */
	public function paymentOf(array|string $invoice): string
	{
		if (is_string($invoice) || !is_array($invoice['payments'] ?? null)) {
			$id = is_string($invoice) ? $invoice : $this->text($invoice, 'id');
			if ($id === '') {
				return '';
			}
			$invoice = $this->client->get('/invoices/' . rawurlencode($id), ['expand' => ['payments']]);
		}

		$payments = is_array($invoice['payments'] ?? null) && is_array($invoice['payments']['data'] ?? null) ? $invoice['payments']['data'] : [];
		foreach ($payments as $payment) {
			$with   = is_array($payment) && is_array($payment['payment'] ?? null) ? $payment['payment'] : [];
			$intent = $with['payment_intent'] ?? '';
			// Asked for by id it is a string; expanded further it is the object.
			$intent = is_array($intent) ? ($intent['id'] ?? '') : $intent;
			if (is_string($intent) && $intent !== '') {
				return $intent;
			}
		}

		return '';
	}

	/** @param array<array-key,mixed> $invoice */
	private function subscriptionOf(array $invoice): string
	{
		$details = $this->details($invoice);
		$ref     = $details['subscription'] ?? ($invoice['subscription'] ?? '');
		$ref     = is_array($ref) ? ($ref['id'] ?? '') : $ref;

		return is_string($ref) ? $ref : '';
	}

	/**
	 * The store's own id for the subscription an invoice bills, which
	 * checkout wrote on the subscription. '' when Stripe did not copy it
	 * to the invoice: the store then finds it by Stripe's id.
	 *
	 * @param array<array-key,mixed> $invoice
	 */
	private function engineId(array $invoice): string
	{
		return $this->metadataId($this->details($invoice));
	}

	/**
	 * @param array<array-key,mixed> $invoice
	 *
	 * @return array<array-key,mixed>
	 */
	private function details(array $invoice): array
	{
		$parent = is_array($invoice['parent'] ?? null) ? $invoice['parent'] : [];

		return is_array($parent['subscription_details'] ?? null) ? $parent['subscription_details'] : [];
	}

	/** @param array<array-key,mixed> $object */
	private function metadataId(array $object): string
	{
		$metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

		return $this->text($metadata, 'subscriptionId');
	}

	/**
	 * The tax on an invoice: all of it, or only what is of one kind
	 * (`exclusive`: added on top of the prices; `inclusive`: part of them).
	 *
	 * @param array<array-key,mixed> $invoice
	 */
	private function taxOf(array $invoice, string $behavior = ''): int
	{
		$tax = 0;
		foreach (is_array($invoice['total_taxes'] ?? null) ? $invoice['total_taxes'] : [] as $entry) {
			if (is_array($entry) && ($behavior === '' || ($entry['tax_behavior'] ?? '') === $behavior)) {
				$tax += $this->int($entry, 'amount');
			}
		}

		return $tax;
	}

	/**
	 * When the period this invoice paid for ends, from its first line.
	 *
	 * @param array<array-key,mixed> $invoice
	 */
	private function periodEndOf(array $invoice): ?int
	{
		$lines  = is_array($invoice['lines'] ?? null) && is_array($invoice['lines']['data'] ?? null) ? array_values(array_filter($invoice['lines']['data'], is_array(...))) : [];
		$period = is_array($lines[0]['period'] ?? null) ? $lines[0]['period'] : [];

		return is_int($period['end'] ?? null) ? $period['end'] : null;
	}

	/** @param array<array-key,mixed> $object */
	private function int(array $object, string $key): int
	{
		$value = $object[$key] ?? 0;

		return is_numeric($value) ? max(0, (int)$value) : 0;
	}

	/** @param array<array-key,mixed> $object */
	private function text(array $object, string $key): string
	{
		$value = $object[$key] ?? '';

		return is_string($value) ? $value : '';
	}
}
