<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionAdmin;

/**
 * POST /admin/commerce/subscriptions/{id}/dismiss: mark a subscription
 * that needs someone as handled. It leaves the count in the sidebar, and
 * keeps why it was there and who dealt with it. Nothing is asked of the
 * provider, so it is for anyone who may work on the store.
 */
final readonly class AdminSubscriptionDismissAction
{
	public function __construct(
		private SubscriptionActionResponder $responder,
		private SubscriptionAdmin $admin,
		private ActorContext $actors,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		return $this->responder->run($request, $response, $args['id'] ?? '', function (string $id): string {
			$this->admin->dismiss($id, $this->actors->current());

			return 'Marked as handled. This subscription is no longer counted as needing attention.';
		});
	}
}
