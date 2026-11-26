<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Order\OrderAdmin;

/**
 * POST /admin/commerce/orders/{id}/note: keep a note on the order, for
 * staff only. Body: `notes`. An empty one clears it.
 */
final readonly class AdminOrderNoteAction
{
	public function __construct(
		private OrderActionResponder $responder,
		private OrderAdmin $admin,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$body  = (array)$request->getParsedBody();
		$notes = is_string($body['notes'] ?? null) ? $body['notes'] : '';

		return $this->responder->run($request, $response, $args['id'] ?? '', function (string $id) use ($notes): string {
			return $this->admin->note($id, $notes)->notes() === '' ? 'Note cleared.' : 'Note saved.';
		});
	}
}
