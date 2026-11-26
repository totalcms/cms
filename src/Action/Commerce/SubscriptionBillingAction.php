<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;

/**
 * GET /commerce/subscription/{id}/billing: sends the subscriber to the
 * provider's own page, where they change their card and see their
 * invoices. The store never sees a card.
 */
final readonly class SubscriptionBillingAction
{
	public function __construct(
		private SubscriptionPage $page,
		private PaymentProviderRegistry $providers,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$subscription = $this->page->subscription($request);
		$provider     = $this->providers->subscriptionProvider($subscription->provider());

		$url = $provider === null || $subscription->providerCustomerRef() === ''
			? ''
			: $provider->billingPortalUrl($subscription->providerCustomerRef(), $this->page->url($request, $subscription, '', [], true));
		if ($url === '') {
			throw new HttpNotFoundException($request);
		}

		return $response->withStatus(303)->withHeader('Location', $url);
	}
}
