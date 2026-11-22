<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Pricing\PricingContextFactory;
use TotalCMS\Domain\Commerce\Pricing\PricingService;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\MoneyFilter;
use TotalCMS\Domain\Commerce\Service\ProductCatalog;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Query\Service\TemplateQuery;

/**
 * `cms.commerce`: the store's catalog and prices in a site's own templates.
 *
 * A visitor sees active products only. With the store switched off there is
 * nothing to show, and nothing here throws on a public page.
 */
final readonly class CommerceTwigAdapter
{
	private const STATUS = 'status';

	public function __construct(
		private CommerceGate $gate,
		private CommerceSettings $settings,
		private ProductCatalog $catalog,
		private PricingService $pricing,
		private PricingContextFactory $contexts,
		private TemplateQuery $query,
		private ActorContext $actors,
		private MoneyFilter $money,
	) {
	}

	/** Whether the store is switched on for this site. */
	public function enabled(): bool
	{
		return $this->gate->allowed();
	}

	/** The store's currency, as a three-letter code. */
	public function currency(): string
	{
		return $this->settings->currency();
	}

	/**
	 * Active products, with the options of `cms.collection.query()`: filter,
	 * sort, search, limit, page. Whatever the filter says about status, only
	 * active products are listed.
	 *
	 * @param array<mixed> $options
	 *
	 * @return array{objects: array<int,array<string,mixed>>, total: int, limit: int, offset: int, page: int, pages: int, hasMore: bool}
	 */
	public function products(array $options = []): array
	{
		if (!$this->gate->allowed()) {
			return (new QueryResult([], 0, 0, 0))->forTemplate();
		}

		$asked  = is_array($options['filter'] ?? null) ? $options['filter'] : [];
		$filter = array_values(array_filter($asked, static fn (mixed $rule): bool => is_array($rule) && ($rule['property'] ?? '') !== self::STATUS));

		$options['filter'] = [...$filter, ['property' => self::STATUS, 'value' => ProductView::STATUS_ACTIVE]];

		try {
			return $this->query->collection($this->settings->collection('products'), $options)->forTemplate();
		} catch (\Throwable) {
			return (new QueryResult([], 0, 0, 0))->forTemplate();
		}
	}

	/**
	 * One product, with every field it has, or null. A visitor gets active
	 * products only; staff also get drafts and archived ones, to preview a page.
	 *
	 * @return array<string,mixed>|null
	 */
	public function product(string $id): ?array
	{
		return $this->visible($id) instanceof ProductView ? $this->catalog->object($id) : null;
	}

	/**
	 * What a product costs this buyer, or null when they cannot see it. With
	 * no variant named it is the product's own price.
	 *
	 * @return array{amount: int, listAmount: int, currency: string, formatted: string, listFormatted: string, source: string}|null
	 */
	public function price(string $productId, string $variantId = ''): ?array
	{
		$product = $this->visible($productId);
		if (!$product instanceof ProductView) {
			return null;
		}

		$variant = $product->variants[$variantId] ?? null;
		if ($variantId !== '' && ($variant === null || !$variant->active)) {
			return null;
		}

		$priced = $this->pricing->unitPrice($product, $variant, 1, $this->contexts->current());

		return [
			'amount'        => $priced->unitPrice->amount(),
			'listAmount'    => $priced->listPrice->amount(),
			'currency'      => $priced->unitPrice->currency(),
			'formatted'     => ($this->money)($priced->unitPrice->amount(), $priced->unitPrice->currency()),
			'listFormatted' => ($this->money)($priced->listPrice->amount(), $priced->listPrice->currency()),
			'source'        => $priced->source,
		];
	}

	/**
	 * Whether a product (or one variant of it) can be bought now. A product
	 * with variants and none named is in stock when any of them is.
	 */
	public function inStock(string $productId, string $variantId = ''): bool
	{
		if (!$this->gate->allowed()) {
			return false;
		}

		$product = $this->catalog->product($productId);
		if (!$product instanceof ProductView || !$product->isActive()) {
			return false;
		}

		$variants = array_filter($product->variants, static fn ($variant): bool => $variant->active);
		if ($variantId !== '') {
			$variants = array_intersect_key($variants, [$variantId => true]);
		}
		if ($product->hasVariants && $variants === []) {
			return false;
		}
		if (!$product->trackInventory || $product->allowBackorder) {
			return true;
		}

		$stock = $product->hasVariants ? max(array_map(static fn ($variant): int => $variant->stock, $variants)) : $product->stock;

		return $stock > 0;
	}

	/**
	 * The payment providers a buyer can choose from.
	 *
	 * @return list<array{id: string, label: string}>
	 */
	public function providers(): array
	{
		return [];
	}

	/** An amount in minor units written for a person, in the store currency unless another is named. */
	public function format(mixed $amount, ?string $currency = null): string
	{
		return ($this->money)($amount, $currency);
	}

	/** The product as this visitor may see it: active for anyone, any status for staff. */
	private function visible(string $id): ?ProductView
	{
		if (!$this->gate->allowed()) {
			return null;
		}

		$product = $this->catalog->product($id);
		if (!$product instanceof ProductView) {
			return null;
		}

		$staff = in_array($this->actors->current()->kind, [ActorKind::Admin, ActorKind::System], true);

		return $product->isActive() || $staff ? $product : null;
	}
}
