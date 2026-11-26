<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /commerce/order/{orderId}/status?t=…: where the payment stands, as
 * the part of the order page that reloads itself while it waits.
 */
final readonly class OrderStatusAction
{
	public function __construct(private OrderPage $page)
	{
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		return $this->page->poll($request, $response, $this->page->order($request, $args['orderId'] ?? ''));
	}
}
