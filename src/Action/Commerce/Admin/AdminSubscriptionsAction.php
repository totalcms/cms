<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionQuery;
use TotalCMS\Renderer\TwigRenderer;

/**
 * GET /admin/commerce/subscriptions: the store's subscriptions, newest
 * first, filtered and searched by the query string.
 *
 * Like the orders list, a request from the page's own filter form (HTMX)
 * is answered with the table alone.
 */
final readonly class AdminSubscriptionsAction
{
	private const PER_PAGE = 50;

	private const FILTERS = ['q', 'status', 'needsAttention'];

	public function __construct(
		private TwigRenderer $twig,
		private SubscriptionQuery $query,
		private SubscriptionPageView $view,
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

		$vars = [
			'url'     => ['path' => $request->getUri()->getPath(), 'query' => $request->getUri()->getQuery(), 'page' => 'commerce'],
			'rows'    => array_map(fn (SubscriptionData $subscription): array => [
				'subscription' => $subscription,
				'plan'         => $this->view->plan($subscription),
				'status'       => $this->view->status($subscription),
				'attention'    => $this->view->attention($subscription),
			], $found['subscriptions']),
			'total'   => $found['total'],
			'page'    => $page,
			'pages'   => max(1, (int)ceil($found['total'] / self::PER_PAGE)),
			'filters' => $filters,
			'choices' => $this->view->choices(),
			'running' => $this->query->running(),
			'query'   => http_build_query($filters),
		];

		$partial = $request->getHeaderLine('HX-Request') === 'true';

		return $this->twig->template($response, $partial ? 'admin/commerce/partials/subscriptions-table.twig' : 'admin/commerce/subscriptions.twig', $vars);
	}
}
