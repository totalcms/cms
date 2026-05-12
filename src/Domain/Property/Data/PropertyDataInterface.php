<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Data;

/**
 * Property data interface.
 */
interface PropertyDataInterface
{
	/**
	 * Transform property data to serializable data.
	 */
	public function transform(): mixed;
}
