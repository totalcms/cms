<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderQuery;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Index\Service\IndexReader;

/**
 * The store's numbers for the admin's home page: what it took lately, its
 * newest orders, what is running out, and how many orders need someone.
 *
 * Answered from the orders and products indexes. An order is opened only
 * when it was part refunded (to see how much went back), and a product only
 * when it tracks stock (to see its variants).
 */
final readonly class CommerceDashboard
{
	/** The most tracked products opened to look at their stock. */
	private const PRODUCTS_CHECKED = 300;

	public function __construct(
		private OrderRepository $orders,
		private OrderQuery $query,
		private ProductCatalog $catalog,
		private IndexReader $index,
		private CommerceSettings $settings,
	) {
	}

	/**
	 * What the store took in the last $days days and kept: every order paid
	 * in that time, less what was refunded from it. An order nobody paid, one
	 * in review and one fully refunded add nothing.
	 *
	 * @return array{amount: int, currency: string, orders: int}
	 */
	public function revenue(int $days = 30): array
	{
		$since  = time() - max(1, $days) * 86400;
		$amount = 0;
		$count  = 0;

		foreach ($this->orders->index() as $row) {
			$status = $row['paymentStatus'] ?? '';
			$paidAt = is_string($row['paidAt'] ?? null) && $row['paidAt'] !== '' ? strtotime($row['paidAt']) : false;
			if (!in_array($status, ['paid', 'partially_refunded', 'disputed'], true) || $paidAt === false || $paidAt < $since) {
				continue;
			}

			$total = is_numeric($row['grandTotal'] ?? null) ? (int)$row['grandTotal'] : 0;
			// The index does not hold what was refunded; a part refunded order is opened for it.
			if ($status !== 'paid' && is_string($row['id'] ?? null)) {
				$total -= $this->orders->find($row['id'])?->refundedTotal() ?? 0;
			}

			$amount += max(0, $total);
			$count++;
		}

		return ['amount' => $amount, 'currency' => $this->settings->currency(), 'orders' => $count];
	}

	/**
	 * The newest orders, paid or not.
	 *
	 * @return list<OrderData>
	 */
	public function recentOrders(int $limit = 5): array
	{
		return $this->query->search([], 1, max(1, $limit))['orders'];
	}

	/**
	 * Active products, and variants, that track stock and have $threshold
	 * or fewer left, lowest first. A product with variants is listed by its
	 * variants: its own stock number is not what is sold.
	 *
	 * @param int|null $threshold the store's Low Stock At when null
	 *
	 * @return list<array{productId: string, variantId: string, title: string, stock: int}>
	 */
	public function lowStock(?int $threshold = null): array
	{
		$threshold ??= $this->settings->lowStockAt();
		$low     = [];
		$checked = 0;
		foreach ($this->index->fetchIndex($this->settings->collection('products'))->objects as $row) {
			if (!is_string($row['id'] ?? null) || !filter_var($row['trackInventory'] ?? false, FILTER_VALIDATE_BOOLEAN) || ($row['status'] ?? '') !== ProductView::STATUS_ACTIVE) {
				continue;
			}
			if (++$checked > self::PRODUCTS_CHECKED) {
				break;
			}

			$product = $this->catalog->product($row['id']);
			if (!$product instanceof ProductView || !$product->trackInventory) {
				continue;
			}

			if (!$product->hasVariants) {
				if ($product->stock <= $threshold) {
					$low[] = ['productId' => $product->id, 'variantId' => '', 'title' => $product->title, 'stock' => $product->stock];
				}
				continue;
			}
			foreach ($product->variants as $variant) {
				if ($variant->active && $variant->stock <= $threshold) {
					$low[] = ['productId' => $product->id, 'variantId' => $variant->id, 'title' => $product->title . ' — ' . $variant->title, 'stock' => $variant->stock];
				}
			}
		}

		usort($low, static fn (array $a, array $b): int => [$a['stock'], $a['title']] <=> [$b['stock'], $b['title']]);

		return $low;
	}

	/** How many orders need someone. */
	public function attention(): int
	{
		return $this->query->attentionCount();
	}
}
