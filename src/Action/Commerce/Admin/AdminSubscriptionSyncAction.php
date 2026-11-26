<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionAdmin;

/**
 * POST /admin/commerce/subscriptions/{id}/sync: ask the provider where a
 * subscription stands, and make the store's record and the subscriber's
 * access match. For when a webhook was missed.
 */
final readonly class AdminSubscriptionSyncAction
{
	public function __construct(
		private SubscriptionActionResponder $responder,
		private SubscriptionAdmin $admin,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		return $this->responder->run($request, $response, $args['id'] ?? '', function (string $id): string {
			$this->admin->sync($id);

			return 'Checked with the payment provider. This is where the subscription stands.';
		});
	}
}
