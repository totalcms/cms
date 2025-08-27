<?php

namespace TotalCMS\Domain\Index\Data;

use Illuminate\Support\Collection;

/**
 * Data object.
 */
class IndexData
{
	/** @var Collection<int,array<string,mixed>> */
	public Collection $objects;

	/** @param array<int,array<string,mixed>> $objects */
	public function __construct(array $objects = [])
	{
		$this->objects = collect($objects);
	}
}
