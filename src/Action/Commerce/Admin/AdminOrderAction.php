<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Renderer\TwigRenderer;

/**
 * GET /admin/commerce/orders/{id}: one order, with everything the store
 * knows about it: what was bought, what it came to, who bought it, and
 * every movement of the money.
 *
 * What the page shows is worked out by {@see OrderPageView}.
 */
final readonly class AdminOrderAction
{
	public function __construct(
		private TwigRenderer $twig,
		private OrderRepository $orders,
		private OrderPageView $view,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$id    = $args['id'] ?? '';
		$url   = ['path' => $request->getUri()->getPath(), 'query' => $request->getUri()->getQuery(), 'page' => 'commerce'];
		$order = preg_match('/^[a-f0-9]{32}\z/', $id) === 1 ? $this->orders->find($id) : null;
		if (!$order instanceof OrderData) {
			return $this->twig->template($response->withStatus(404), 'admin/404.twig', ['url' => ['page' => '404'] + $url]);
		}

		return $this->twig->template($response, 'admin/commerce/order.twig', ['url' => $url] + $this->view->of($order));
	}
}
