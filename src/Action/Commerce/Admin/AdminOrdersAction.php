<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderLabels;
use TotalCMS\Domain\Commerce\Order\OrderQuery;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Renderer\TwigRenderer;

/**
 * GET /admin/commerce[/orders]: the store's orders, newest first, filtered
 * and searched by the query string.
 *
 * A request from the page's own filter form (HTMX) is answered with the
 * table alone; the address bar is given the same query, so a filtered list
 * can be bookmarked and reloaded.
 */
final readonly class AdminOrdersAction
{
	private const PER_PAGE = 50;

	private const FILTERS = ['q', 'paymentStatus', 'fulfillmentStatus', 'needsAttention', 'from', 'to'];

	public function __construct(
		private TwigRenderer $twig,
		private OrderQuery $query,
		private OrderLabels $labels,
		private CommerceSettings $settings,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$params  = $request->getQueryParams();
		$filters = [];
		foreach (self::FILTERS as $name) {
			$value = $params[$name] ?? '';
			if (is_string($value) && trim($value) !== '') {
				$filters[$name] = trim($value);
			}
		}

		$page  = max(1, is_numeric($params['page'] ?? null) ? (int)$params['page'] : 1);
		$found = $this->query->search($filters, $page, self::PER_PAGE);
		$pages = max(1, (int)ceil($found['total'] / self::PER_PAGE));

		$vars = [
			'url'     => ['path' => $request->getUri()->getPath(), 'query' => $request->getUri()->getQuery(), 'page' => 'commerce'],
			'rows'    => array_map($this->row(...), $found['orders']),
			'total'   => $found['total'],
			'page'    => $page,
			'pages'   => $pages,
			'filters' => $filters,
			'choices' => $this->labels->choices(),
			'prefix'  => $this->settings->orderNumberPrefix(),
			// The filters as a query string, for the links to the pages before and after.
			'query'   => http_build_query($filters),
		];

		$partial = $request->getHeaderLine('HX-Request') === 'true';

		return $this->twig->template($response, $partial ? 'admin/commerce/partials/orders-table.twig' : 'admin/commerce/orders.twig', $vars);
	}

	/**
	 * One row of the list: the order, and how its states read.
	 *
	 * @return array{order: OrderData, payment: array{label: string, tone: string}, fulfillment: array{label: string, tone: string}, attention: string}
	 */
	private function row(OrderData $order): array
	{
		return [
			'order'       => $order,
			'payment'     => $this->labels->payment($order->paymentStatus()),
			'fulfillment' => $this->labels->fulfillment($order->fulfillmentStatus(), $order->paidAt() !== ''),
			'attention'   => $this->labels->attention($this->query->attentionReason($order)),
		];
	}
}
