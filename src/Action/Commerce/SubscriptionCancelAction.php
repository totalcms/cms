<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionAdmin;
use TotalCMS\Renderer\JsonRenderer;

/**
 * POST /commerce/subscription/{id}/cancel: the subscriber asks to stop.
 *
 * It asks the provider to cancel at the end of the period already paid
 * for, and writes nothing. The subscription changes when the provider's
 * own word comes back, like every other change to one: a button cannot
 * end what a provider is still charging for.
 */
final readonly class SubscriptionCancelAction
{
	public function __construct(
		private SubscriptionPage $page,
		private SubscriptionAdmin $admin,
		private JsonRenderer $json,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$subscription = $this->page->subscription($request);

		try {
			$this->admin->cancel($subscription->id(), true);
		} catch (CommerceException $e) {
			if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
				return $this->json->json($response, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status);
			}

			return $response->withStatus(303)->withHeader('Location', $this->page->url($request, $subscription, '', ['problem' => '1']));
		}

		return $response->withStatus(303)->withHeader('Location', $this->page->url($request, $subscription, '', ['requested' => '1']));
	}
}
