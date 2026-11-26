<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Subscription\CustomerSubscriptions;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Renderer\TwigRenderer;

/**
 * GET /commerce/subscription/{id}: a subscriber's own page. What they pay
 * for and when, the payments made so far, and the ways to cancel and to
 * change their card.
 *
 * Open to a store whose Commerce add-on has lapsed: a subscriber is still
 * being charged, and must still be able to see and stop it.
 */
final readonly class SubscriptionViewAction
{
	public function __construct(
		private SubscriptionPage $page,
		private CustomerSubscriptions $subscriptions,
		private PaymentProviderRegistry $providers,
		private CSRFTokenManager $csrf,
		private TwigRenderer $twig,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$subscription = $this->page->subscription($request);
		$provider     = $this->providers->subscriptionProvider($subscription->provider());
		$running      = $subscription->isLive() && $subscription->providerSubscriptionRef() !== '' && $provider !== null;
		$query        = $request->getQueryParams();

		return $this->twig->template($response->withHeader('Content-Type', 'text/html; charset=utf-8'), 'commerce/subscription.twig', [
			'subscription' => $this->subscriptions->view($subscription),
			'csrf'         => $this->csrf->getTokenField(),
			// The buyer can ask to cancel once: after that it ends with the period.
			'cancelUrl'    => $running && !$subscription->cancelAtPeriodEnd() ? $this->page->url($request, $subscription, '/cancel') : '',
			'billingUrl'   => $running && $subscription->providerCustomerRef() !== '' && $provider->capabilities()->billingPortal ? $this->page->url($request, $subscription, '/billing') : '',
			'requested'    => isset($query['requested']),
			'problem'      => isset($query['problem']),
		]);
	}
}
