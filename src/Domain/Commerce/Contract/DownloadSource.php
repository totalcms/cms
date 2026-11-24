<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Contract;

use TotalCMS\Domain\Commerce\Download\Deliverable;
use TotalCMS\Domain\Commerce\Download\Delivery;

/**
 * A place a product's download can be kept.
 *
 * The store has two of its own: a file on the product, and a link. An
 * extension adds another with `ExtensionContext::addDownloadSource()`, as
 * S3 Uploads does for a file kept in a bucket.
 *
 * A source never decides who may download. The store checks the buyer's
 * signed link, that the order is still paid and that downloads are left,
 * and counts the download, before it asks a source to hand anything over.
 */
interface DownloadSource
{
	/** A short name for the source, such as `file`, `url` or `s3`. */
	public function id(): string;

	/**
	 * What this product gives a buyer from here, or null when it has
	 * nothing here. Asked whenever an order is shown, so it must be cheap:
	 * no link is signed and no file is opened.
	 *
	 * @param string              $collection the collection products are kept in
	 * @param array<string,mixed> $product    the stored product
	 */
	public function find(string $collection, array $product): ?Deliverable;

	/**
	 * Hand it over: the file's bytes, or an address to send the buyer on to.
	 * Asked once for each download, after it has been counted.
	 *
	 * @param array<string,mixed> $product the stored product
	 */
	public function deliver(string $collection, array $product): ?Delivery;
}
