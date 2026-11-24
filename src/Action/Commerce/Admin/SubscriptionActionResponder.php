<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionRepository;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;

/**
 * How every action on a subscription answers, the way an order's do: the
 * page's own buttons (HTMX) get the subscription redrawn with a line
 * saying what was done or why it was refused; any other caller gets JSON.
 */
final readonly class SubscriptionActionResponder
{
	public function __construct(
		private SubscriptionRepository $subscriptions,
		private SubscriptionPageView $view,
		private TwigRenderer $twig,
		private JsonRenderer $json,
	) {
	}

	/** @param \Closure(string): string $action given the subscription's id; returns what to tell staff was done */
	public function run(ServerRequestInterface $request, ResponseInterface $response, string $subscriptionId, \Closure $action): ResponseInterface
	{
		$htmx = $request->getHeaderLine('HX-Request') === 'true';

		try {
			// An id that is not a subscription's is not looked up at all.
			if (preg_match('/^[a-f0-9]{32}\z/', $subscriptionId) !== 1) {
				throw CommerceException::subscriptionNotFound();
			}
			$done = $action($subscriptionId);
		} catch (CommerceException $e) {
			$subscription = $htmx && $e->errorCode !== 'subscription_not_found' ? $this->subscriptions->find($subscriptionId) : null;

			return $subscription instanceof SubscriptionData
				? $this->page($response->withStatus($e->status), $subscription, ['error' => $e->getMessage()])
				: $this->json->json($response->withStatus($e->status), ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
		}

		$subscription = $this->subscriptions->find($subscriptionId);
		if ($htmx && $subscription instanceof SubscriptionData) {
			return $this->page($response, $subscription, ['notice' => $done]);
		}

		return $this->json->json($response, ['ok' => true, 'message' => $done]);
	}

	/** @param array<string,string> $with */
	private function page(ResponseInterface $response, SubscriptionData $subscription, array $with): ResponseInterface
	{
		return $this->twig->template($response, 'admin/commerce/partials/subscription-body.twig', $with + $this->view->of($subscription));
	}
}
