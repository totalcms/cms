<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Commerce\Download\DownloadProtection;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderLabels;
use TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter;

/**
 * The store's part of the admin home page, for whoever is looking.
 *
 * It answers only on a site with Commerce and only for a user who may see
 * orders. The check is here, not in the template that prints it: `cms.commerce`
 * is also there in a site's public templates, and what this holds (buyers'
 * emails, what the store took) must never be something a page can print for
 * a visitor.
 */
final readonly class CommerceDashboardView
{
	private const DAYS = 30;

	public function __construct(
		private CommerceGate $gate,
		private AuthTwigAdapter $auth,
		private CommerceDashboard $dashboard,
		private OrderLabels $labels,
		private CommerceSettings $settings,
		private MoneyFilter $money,
		private DownloadProtection $protection,
		private CollectionFetcher $collections,
	) {
	}

	/**
	 * @return array{days: int, revenue: string, paidOrders: int, attention: int, prefix: string, recent: list<array<string,mixed>>, lowStock: list<array{productId: string, variantId: string, title: string, stock: int}>, lowStockAt: int, productsCollection: string, openDownloads: array{id: string, name: string, anySignedIn: bool}|null}|null
	 *                                                                                                                                                                                                                          null when there is no store, or this user may not see it
	 */
	public function forCurrentUser(): ?array
	{
		if (!$this->gate->allowed() || !$this->auth->canAccessCommerce()) {
			return null;
		}

		$revenue = $this->dashboard->revenue(self::DAYS);
		$open    = $this->protection->openDownloads();

		return [
			'days'       => self::DAYS,
			'revenue'    => ($this->money)($revenue['amount'], $revenue['currency']),
			'paidOrders' => $revenue['orders'],
			'attention'  => $this->dashboard->attention(),
			'prefix'     => $this->settings->orderNumberPrefix(),
			'recent'     => array_map(fn (OrderData $order): array => [
				'id'      => $order->id(),
				'number'  => $order->number(),
				'email'   => $order->email(),
				'created' => $order->created(),
				'total'   => ($this->money)($order->grandTotal(), $order->currency()),
				'payment' => $this->labels->payment($order->paymentStatus()),
			], $this->dashboard->recentOrders()),
			'lowStock'   => $this->dashboard->lowStock(),
			'lowStockAt' => $this->settings->lowStockAt(),
			'productsCollection' => $this->settings->collection('products'),
			'openDownloads'      => $open === null ? null : [
				'id'          => $open->collection,
				'name'        => $this->collections->fetchCollection($open->collection)->name ?? $open->collection,
				'anySignedIn' => $open->anySignedIn,
			],
		];
	}
}
