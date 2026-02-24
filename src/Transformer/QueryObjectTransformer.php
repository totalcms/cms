<?php

declare(strict_types=1);

namespace TotalCMS\Transformer;

use League\Fractal;

/**
 * Passthrough transformer for collection query objects.
 *
 * Index objects are already arrays, so this transformer
 * returns them as-is for the Fractal JSON response.
 */
class QueryObjectTransformer extends Fractal\TransformerAbstract
{
	/**
	 * @param array<string,mixed> $object
	 *
	 * @return array<string,mixed>
	 */
	public function transform(array $object): array
	{
		return $object;
	}
}
