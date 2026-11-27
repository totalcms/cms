<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Serving;

use TotalCMS\Bundled\S3Uploads\Storage\Pointer;
use TotalCMS\Domain\Commerce\Contract\DownloadSource;
use TotalCMS\Domain\Commerce\Download\Deliverable;
use TotalCMS\Domain\Commerce\Download\Delivery;
use TotalCMS\Domain\Commerce\Download\DownloadSources;

/**
 * A store product's download, kept in the bucket: the product's Download
 * field, when the store's product schema makes it an `s3file` field.
 *
 * The store checks the buyer's own link and counts the download on their
 * order; this only says what is in the bucket and signs a link to it. The
 * link is always a signed one, which works for a short time and saves the
 * file under its own name. In a public bucket the file is also readable at
 * its public address by anyone who has it, so a paid file wants a private
 * bucket.
 */
final readonly class ProductDownloadSource implements DownloadSource
{
	/**
	 * Each is resolved on first use: services cannot be built while
	 * extensions register.
	 *
	 * @param \Closure(): PointerLocator  $locator
	 * @param \Closure(): UrlResolver     $urls
	 * @param \Closure(): DownloadCounter $counter
	 */
	public function __construct(
		private \Closure $locator,
		private \Closure $urls,
		private \Closure $counter,
	) {
	}

	public function id(): string
	{
		return 's3';
	}

	public function fieldType(): string
	{
		return 's3file';
	}

	public function find(string $collection, array $product): ?Deliverable
	{
		$pointer = $this->pointer($collection, $product);

		return $pointer instanceof Pointer ? new Deliverable($pointer->name, $pointer->size, DownloadSources::PROPERTY) : null;
	}

	public function deliver(string $collection, array $product): ?Delivery
	{
		$pointer = $this->pointer($collection, $product);
		if (!$pointer instanceof Pointer) {
			return null;
		}

		// The field's own count of downloads, beside the buyer's on their order.
		($this->counter)()->increment($collection, self::productId($product), DownloadSources::PROPERTY);

		return Delivery::redirect($pointer->name, ($this->urls)()->downloadUrl($pointer));
	}

	/**
	 * The stored file, through the same rules as every address the
	 * extension serves: an S3 field in the schema, a bucket configured, a
	 * key under this install's prefix.
	 *
	 * @param array<string,mixed> $product
	 */
	private function pointer(string $collection, array $product): ?Pointer
	{
		$id = self::productId($product);
		if ($id === '' || ($product[DownloadSources::PROPERTY] ?? null) === null || $product[DownloadSources::PROPERTY] === []) {
			return null;
		}

		return ($this->locator)()->find($collection, $id, DownloadSources::PROPERTY)['pointer'] ?? null;
	}

	/** @param array<string,mixed> $product */
	private static function productId(array $product): string
	{
		return is_string($product['id'] ?? null) ? $product['id'] : '';
	}
}
