<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\ImageWorks\Service\ImageworksUrl;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;

/**
 * How the store reads its products.
 */
final readonly class ProductCatalog
{
	private const IMAGES = 'images';

	public function __construct(
		private ObjectFetcher $objects,
		private CommerceSettings $settings,
		private Config $config,
		private CollectionFetcher $collections,
		private ObjectUrlBuilder $urls,
	) {
	}

	/**
	 * A product whatever its status, or null when there is none of that id.
	 * Use purchasable() to ask whether it may be bought.
	 */
	public function product(string $id): ?ProductView
	{
		$collection = $this->settings->collection('products');
		if ($id === '' || !$this->objects->existsObject($collection, $id)) {
			return null;
		}

		$object = $this->objects->fetchObject($collection, $id)->toArray();

		return ProductView::fromObject($object, $this->mainImage($collection, $id, $object[self::IMAGES] ?? null));
	}

	/**
	 * A product as it is stored, with every field it has, for a template
	 * that prints its description or gallery. Null when there is none.
	 *
	 * @return array<string,mixed>|null
	 */
	public function object(string $id): ?array
	{
		$collection = $this->settings->collection('products');
		if ($id === '' || !$this->objects->existsObject($collection, $id)) {
			return null;
		}

		return $this->objects->fetchObject($collection, $id)->toArray();
	}

	/** The product's own page, when the products collection has a URL; '' otherwise. */
	public function url(string $id): string
	{
		try {
			$collection = $this->collections->fetchCollection($this->settings->collection('products'));
			$object     = $this->object($id);
			if (!$collection instanceof CollectionData || $object === null || trim($collection->url) === '') {
				return '';
			}

			return $this->urls->buildUrl($collection, $object);
		} catch (\Throwable) {
			return '';
		}
	}

	/**
	 * The product, when this product and variant may be put in a cart.
	 * Stock is not checked here: that is the inventory's answer.
	 *
	 * @param string $variantId '' for a product without variants
	 *
	 * @throws CommerceException `product_unavailable` when the product is unknown or not active;
	 *                           `variant_required` when it has variants and none is named;
	 *                           `variant_invalid` when the variant is unknown or switched off, or one is named for a product without variants;
	 *                           `subscription_ships` for a plan that ships; `product_unavailable` for a plan billed on a period no provider can bill
	 */
	public function purchasable(string $productId, string $variantId): ProductView
	{
		$product = $this->product($productId);
		if (!$product instanceof ProductView || !$product->isActive()) {
			throw CommerceException::productUnavailable();
		}

		if (!$product->hasVariants) {
			if ($variantId !== '') {
				throw CommerceException::variantInvalid();
			}
			$this->refuseShippedPlan($product, null);
			$product->recurrence();

			return $product;
		}
		if ($variantId === '') {
			throw CommerceException::variantRequired();
		}
		if (!isset($product->variants[$variantId]) || !$product->variants[$variantId]->active) {
			throw CommerceException::variantInvalid();
		}
		$this->refuseShippedPlan($product, $product->variants[$variantId]);
		// A plan whose billing period no provider can bill is not for sale.
		$product->recurrence($product->variants[$variantId]);

		return $product;
	}

	/**
	 * A provider bills a period. It does not send a parcel each time.
	 *
	 * @throws CommerceException `subscription_ships`
	 */
	private function refuseShippedPlan(ProductView $product, ?VariantView $variant): void
	{
		if ($product->isRecurring($variant) && $product->requiresShipping) {
			throw CommerceException::subscriptionShips();
		}
	}

	/** The URL of the first image in the product's gallery, or ''. */
	private function mainImage(string $collection, string $id, mixed $gallery): string
	{
		$first = is_array($gallery) ? reset($gallery) : null;
		$name  = is_array($first) && is_string($first['name'] ?? null) ? $first['name'] : '';
		if (!is_array($first) || $name === '') {
			return '';
		}

		return ImageworksUrl::buildImageworksGalleryAPI($this->config->api, $id, $name, $first, [], ['collection' => $collection, 'property' => self::IMAGES]);
	}
}
