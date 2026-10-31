<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Data;

/**
 * What one `imageworks:warm` run did. Counts are per variant except
 * `objects` and `images`; `wouldMake` is only filled by a dry run.
 */
final class WarmReport
{
	public int $objects     = 0;
	public int $images      = 0;
	public int $made        = 0;
	public int $wouldMake   = 0;
	public int $cached      = 0;
	public int $original    = 0;
	public int $uncacheable = 0;
	public int $failed      = 0;

	/** @var list<array{collection: string, id: string, property: string, image: string, params: string, error: string}> */
	public array $failures = [];

	public function fail(string $collection, string $id, string $property, string $image, string $params, string $error): void
	{
		$this->failed++;
		$this->failures[] = [
			'collection' => $collection,
			'id'         => $id,
			'property'   => $property,
			'image'      => $image,
			'params'     => $params,
			'error'      => $error,
		];
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'objects'     => $this->objects,
			'images'      => $this->images,
			'made'        => $this->made,
			'wouldMake'   => $this->wouldMake,
			'cached'      => $this->cached,
			'original'    => $this->original,
			'uncacheable' => $this->uncacheable,
			'failed'      => $this->failed,
			'failures'    => $this->failures,
		];
	}
}
