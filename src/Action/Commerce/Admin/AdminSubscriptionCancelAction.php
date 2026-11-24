<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionAdmin;

/**
 * POST /admin/commerce/subscriptions/{id}/cancel: staff ask the provider
 * to end a subscription. `when` is `now`, or `period_end` (the default):
 * at the end of the period the subscriber has paid for.
 *
 * Like the subscriber's own button it writes nothing. The subscription
 * changes when the provider's webhook says it has.
 */
final readonly class AdminSubscriptionCancelAction
{
	public function __construct(
		private SubscriptionActionResponder $responder,
		private SubscriptionAdmin $admin,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$body = $request->getParsedBody();
		// Anything but a plain "now" is the gentler one.
		$now  = is_array($body) && ($body['when'] ?? '') === 'now';

		return $this->responder->run($request, $response, $args['id'] ?? '', function (string $id) use ($now): string {
			$this->admin->cancel($id, !$now);

			return $now
				? 'The payment provider was asked to cancel this subscription now. This page shows it once the provider confirms.'
				: 'The payment provider was asked to cancel this subscription at the end of the period. This page shows it once the provider confirms.';
		});
	}
}
