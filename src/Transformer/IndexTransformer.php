<?php

namespace TotalCMS\Transformer;

use League\Fractal;
use TotalCMS\Domain\Index\Data\IndexData;

final class IndexTransformer extends Fractal\TransformerAbstract
{
	/**
	 * Fractal transform for a collection index.
	 *
	 * @param IndexData $index The collection index object
	 *
	 * @return array<string,mixed>
	 */
	public function transform(IndexData $index): array
	{
		return [
			'objects' => $index->objects->toArray(),
		];
	}
}
