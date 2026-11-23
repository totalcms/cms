<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Webhook\WebhookVerifier;

/**
 * A way to pay: Stripe, PayPal. An extension implements this and registers
 * it with `$context->addPaymentProvider()`.
 *
 * A provider collects money for an order the engine built. It never decides
 * a price, and it never changes an order: it reports what happened through
 * its webhook, and the engine acts on that.
 */
interface PaymentProvider
{
	/** A stable id, `[a-z0-9-]+`, used in URLs and settings: 'stripe'. */
	public function id(): string;

	public function label(): string;

	public function capabilities(): ProviderCapabilities;

	/** Whether its keys are present and well-formed. Checkout does not offer a provider that says no. */
	public function isConfigured(): bool;

	public function supportsCurrency(string $currency): bool;

	/**
	 * Create a checkout for a server-built order. Never receives a price from the buyer.
	 *
	 * @throws ProviderException
	 */
	public function createCheckout(CheckoutRequest $request): CheckoutSession;

	/**
	 * Called when the buyer comes back. May talk to the provider (PayPal
	 * captures here). MUST NOT change the order: money is confirmed by webhook.
	 *
	 * @throws ProviderException
	 */
	public function completeReturn(OrderView $order, ServerRequestInterface $request): void;

	/** Checks this provider's webhook signature, over the raw body. */
	public function webhookVerifier(): WebhookVerifier;

	/** A verified webhook in the engine's terms. null = an event the engine does not need. */
	public function parseWebhook(ServerRequestInterface $request): ?ProviderEvent;

	/** Ask the provider where a payment stands, for reconciliation. */
	public function fetchPayment(string $providerSessionRef): ?PaymentEvent;

	/** @throws ProviderException */
	public function refund(RefundRequest $request): RefundResult;
}
