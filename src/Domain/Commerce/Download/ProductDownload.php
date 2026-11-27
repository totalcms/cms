<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

/**
 * What a digital product gives its buyer.
 *
 * A product keeps its download in one of several places ({@see
 * DownloadSources}): a file on the product, a file in a bucket, a link.
 * This is the only way the store reads it. The file on the product is
 * protected, like any file, by the File Access Groups of the products
 * collection.
 */
final readonly class ProductDownload
{
	public function __construct(
		private CommerceSettings $settings,
		private ObjectFetcher $objects,
		private DownloadSources $sources,
	) {
	}

	/** What the product gives a buyer, or null when it has nothing to give. */
	public function find(string $productId): ?Deliverable
	{
		$product = $this->product($productId);

		return $product === null ? null : ($this->sources->for($this->settings->collection('products'), $product)[1] ?? null);
	}

	/**
	 * What the product gives a buyer, and whether that is known. Not known
	 * for a product that is gone, or whose download could not be looked for
	 * ({@see DownloadSources::look()}): "none" is then not an answer to act on.
	 *
	 * @return array{0: ?Deliverable, 1: bool}
	 */
	public function look(string $productId): array
	{
		$product = $this->product($productId);
		if ($product === null) {
			return [null, false];
		}

		[$found, $known] = $this->sources->look($this->settings->collection('products'), $product);

		return [$found[1] ?? null, $known];
	}

	/** Hand the product's download over, or null when there is nothing to hand over. */
	public function deliver(string $productId): ?Delivery
	{
		$product = $this->product($productId);
		$found   = $product === null ? null : $this->sources->for($this->settings->collection('products'), $product);

		return $product === null || $found === null ? null : $found[0]->deliver($this->settings->collection('products'), $product);
	}

	/** @return array<string,mixed>|null null for a product that was deleted */
	private function product(string $productId): ?array
	{
		if ($productId === '') {
			return null;
		}

		try {
			return $this->objects->fetchObject($this->settings->collection('products'), $productId)->toArray();
		} catch (\Throwable) {
			return null;
		}
	}
}
