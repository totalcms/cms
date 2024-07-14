<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Property data interface.
 */
interface PropertyDataInterface
{
	/**
	 * Transform property data to serializable data.
	 *
	 * @return mixed
	 */
	public function transform(): mixed;
}
