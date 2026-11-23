<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Payment\CheckoutRequest;
use TotalCMS\Domain\Commerce\Payment\CheckoutSession;
use TotalCMS\Domain\Commerce\Payment\OrderView;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentProvider;
use TotalCMS\Domain\Commerce\Payment\ProviderCapabilities;
use TotalCMS\Domain\Commerce\Payment\ProviderEvent;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Domain\Commerce\Payment\RefundRequest;
use TotalCMS\Domain\Commerce\Payment\RefundResult;
use TotalCMS\Domain\Webhook\WebhookVerifier;

/**
 * A payment provider that talks to nobody. It keeps what it was asked to do,
 * so a test can assert on the request the engine built, and it answers a
 * webhook with whatever event the test queued.
 *
 * For a provider that bills subscriptions or mounts its checkout in the
 * page, use {@see FakeSubscriptionProvider}: what a provider can do is which
 * interfaces its class implements, so it cannot be a constructor flag.
 */
class FakePaymentProvider implements PaymentProvider
{
	/** @var list<CheckoutRequest> */
	public array $checkouts = [];

	/** @var list<RefundRequest> */
	public array $refunds = [];

	/** @var list<string> the order ids completeReturn() was called for */
	public array $returns = [];

	public bool $configured = true;

	/** @var list<string> currencies it takes; empty = all */
	public array $currencies = [];

	/** @var list<ProviderEvent> */
	private array $events = [];

	private bool $failNextCheckout = false;

	public function __construct(
		private readonly bool $signatureValid = true,
		private readonly string $id = 'fake',
	) {
	}

	public function id(): string
	{
		return $this->id;
	}

	public function label(): string
	{
		return 'Fake Pay';
	}

	public function capabilities(): ProviderCapabilities
	{
		return new ProviderCapabilities();
	}

	public function isConfigured(): bool
	{
		return $this->configured;
	}

	public function supportsCurrency(string $currency): bool
	{
		return $this->currencies === [] || in_array($currency, $this->currencies, true);
	}

	public function createCheckout(CheckoutRequest $request): CheckoutSession
	{
		if ($this->failNextCheckout) {
			$this->failNextCheckout = false;

			throw new ProviderException('The fake provider was told to fail.', 'fake_failure');
		}
		$this->checkouts[] = $request;

		return new CheckoutSession('cs_fake_' . $request->orderId, 'https://pay.example/' . $request->orderId);
	}

	public function completeReturn(OrderView $order, ServerRequestInterface $request): void
	{
		$this->returns[] = $order->id;
	}

	public function webhookVerifier(): WebhookVerifier
	{
		$valid = $this->signatureValid;

		return new class($valid) implements WebhookVerifier {
			public function __construct(private readonly bool $valid)
			{
			}

			public function verify(ServerRequestInterface $request): bool
			{
				return $this->valid;
			}
		};
	}

	public function parseWebhook(ServerRequestInterface $request): ?ProviderEvent
	{
		return array_shift($this->events);
	}

	public function fetchPayment(string $providerSessionRef): ?PaymentEvent
	{
		return null;
	}

	public function refund(RefundRequest $request): RefundResult
	{
		$this->refunds[] = $request;

		return new RefundResult(true, 're_fake_' . count($this->refunds));
	}

	/** The next parseWebhook() answers with this event. */
	public function queueEvent(ProviderEvent $event): void
	{
		$this->events[] = $event;
	}

	public function failNextCheckout(): void
	{
		$this->failNextCheckout = true;
	}
}
