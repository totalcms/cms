<?php

declare(strict_types=1);

namespace Tests\Fakes;

use TotalCMS\Domain\Commerce\Payment\CheckoutRequest;
use TotalCMS\Domain\Commerce\Payment\CheckoutSession;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckout;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckoutProvider;
use TotalCMS\Domain\Commerce\Payment\ProviderCapabilities;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionProvider;

/**
 * A fake provider that can do everything Stripe can: bill a subscription and
 * mount its checkout in the store's own page.
 */
final class FakeSubscriptionProvider extends FakePaymentProvider implements SubscriptionProvider, EmbeddedCheckoutProvider
{
	/** @var list<array{ref: string, atPeriodEnd: bool}> */
	public array $cancellations = [];

	private bool $failNextCancel = false;

	public function __construct(bool $signatureValid = true, private readonly bool $embedded = true, string $id = 'fake-sub')
	{
		parent::__construct($signatureValid, $id);
	}

	public function capabilities(): ProviderCapabilities
	{
		return new ProviderCapabilities(embedded: $this->embedded, subscriptions: true, billingPortal: true);
	}

	public function createCheckout(CheckoutRequest $request): CheckoutSession
	{
		$session = parent::createCheckout($request);
		if ($request->uiMode !== 'embedded') {
			return $session;
		}

		return new CheckoutSession('cs_fake_' . $request->orderId, '', 'secret_' . $request->orderId);
	}

	public function embed(CheckoutSession $session): EmbeddedCheckout
	{
		return new EmbeddedCheckout('/fake/embed.js', ['clientSecret' => (string)$session->clientSecret]);
	}

	public function cancelSubscription(string $providerSubscriptionRef, bool $atPeriodEnd): void
	{
		if ($this->failNextCancel) {
			$this->failNextCancel = false;

			throw new ProviderException('The fake provider was told to fail.', 'fake_failure');
		}
		$this->cancellations[] = ['ref' => $providerSubscriptionRef, 'atPeriodEnd' => $atPeriodEnd];
	}

	public function fetchSubscription(string $providerSubscriptionRef): ?SubscriptionEvent
	{
		return null;
	}

	public function billingPortalUrl(string $providerCustomerRef, string $returnUrl): string
	{
		return 'https://pay.example/portal/' . $providerCustomerRef;
	}

	public function failNextCancel(): void
	{
		$this->failNextCancel = true;
	}
}
