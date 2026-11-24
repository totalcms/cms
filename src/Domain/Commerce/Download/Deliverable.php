<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

/**
 * What a product gives its buyer, as a buyer is told of it.
 */
final readonly class Deliverable
{
	/**
	 * @param string $name     the file's name
	 * @param int    $size     its size in bytes; 0 when that is not known
	 * @param string $property the product field it is kept in
	 */
	public function __construct(
		public string $name,
		public int $size = 0,
		public string $property = '',
	) {
	}
}
