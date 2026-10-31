<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

use InvalidArgumentException;
use TotalCMS\Domain\ImageWorks\Data\CacheState;
use TotalCMS\Domain\ImageWorks\Data\WarmReport;
use TotalCMS\Domain\ImageWorks\Data\WarmVariants;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Pre-builds the ImageWorks cache for a collection: for every image and
 * gallery image, every variant `WarmVariants` asks for is checked with
 * `ImageGenerator::cacheState()` and, when missing, made through the same
 * `generate*()` entry point the public route uses, so both land on one file.
 *
 * `ImageGenerator` keeps per-call state in properties (collection, id,
 * params…), so its calls must stay sequential — do not parallelize them
 * over one instance.
 */
final readonly class ImageWorksWarmer
{
	private const FIELDS = ['image', 'gallery'];

	public function __construct(
		private ImageGenerator $generator,
		private SchemaFetcher $schemaFetcher,
		private IndexReader $indexReader,
		private ObjectFetcher $objectFetcher,
		private EditionFeatureService $editionFeatures,
	) {
	}

	/**
	 * The collection's image and gallery properties, or the one named.
	 *
	 * @return array<string,string> name => 'image'|'gallery'
	 *
	 * @throws InvalidArgumentException
	 */
	public function properties(string $collection, ?string $property): array
	{
		$found = [];
		foreach ($this->schemaFetcher->fetchSchemaForCollection($collection)->properties as $name => $definition) {
			$field = is_array($definition) ? ($definition['field'] ?? null) : null;
			if (in_array($field, self::FIELDS, true)) {
				$found[(string)$name] = $field;
			}
		}

		if ($property !== null) {
			if (!isset($found[$property])) {
				throw new InvalidArgumentException("Property {$property} in collection {$collection} is not an image or gallery field.");
			}

			return [$property => $found[$property]];
		}

		if ($found === []) {
			throw new InvalidArgumentException("Collection {$collection} has no image or gallery properties.");
		}

		return $found;
	}

	/**
	 * The first property with a watermark this edition can't apply, or null.
	 * The route strips such a watermark, so warming it would only fill the
	 * cache with files the site never serves. Object overrides count too.
	 *
	 * @param array<string,string> $properties from properties()
	 * @param list<string>         $ids        only these objects; [] for the whole collection
	 */
	public function blockedWatermark(string $collection, array $properties, array $ids = []): ?string
	{
		$image = $this->editionFeatures->can(EditionFeature::IMAGE_WATERMARKS);
		$text  = $this->editionFeatures->can(EditionFeature::TEXT_WATERMARKS);
		if ($image && $text) {
			return null;
		}

		$objects = ['', ...$this->objectIds($collection, $ids, new WarmReport())];
		foreach (array_keys($properties) as $name) {
			foreach ($objects as $id) {
				$mark = $this->generator->watermarkSettings($collection, $name, $id);
				if ((!$image && !empty($mark['mark'])) || (!$text && !empty($mark['marktext']))) {
					return $name;
				}
			}
		}

		return null;
	}

	/**
	 * @param list<string>                   $ids     only these objects; [] for the whole collection
	 * @param null|callable(int, int): void  $onImage called after each image with (done, total)
	 */
	public function warm(string $collection, ?string $property, WarmVariants $variants, array $ids = [], bool $dryRun = false, ?callable $onImage = null): WarmReport
	{
		$report     = new WarmReport();
		$properties = $this->properties($collection, $property);
		$ids        = $this->objectIds($collection, $ids, $report);

		// An object that cannot be read is skipped here and reported below.
		$total = 0;
		foreach ($ids as $id) {
			try {
				$images = $this->objectImages($collection, $id, $properties);
			} catch (\Throwable) {
				continue;
			}
			$total += array_sum(array_map(count(...), $images));
			unset($images);
		}

		$done = 0;
		foreach ($ids as $id) {
			try {
				$images = $this->objectImages($collection, $id, $properties);
			} catch (\Throwable $e) {
				$report->fail($collection, $id, '', '', '', $e->getMessage());
				continue;
			}
			$report->objects++;

			foreach ($images as $name => $list) {
				$field = $properties[$name];
				foreach ($list as $image) {
					$report->images++;
					$galleryName = $field === 'gallery' ? pathinfo((string)$image['name'], PATHINFO_FILENAME) : null;
					foreach ($variants->forImage($image) as $params) {
						$this->warmVariant($report, $collection, $id, $name, $galleryName, (string)$image['name'], $params, $dryRun);
					}

					if ($onImage !== null) {
						$onImage(++$done, $total);
					}
				}
			}
			unset($images);
		}

		return $report;
	}

	/**
	 * The ids to walk: the given ones that exist (unknown ones become failure
	 * rows), or every id in the index.
	 *
	 * @param list<string> $ids
	 *
	 * @return list<string>
	 */
	private function objectIds(string $collection, array $ids, WarmReport $report): array
	{
		$known = [];
		foreach ($this->indexReader->fetchIndex($collection)->objects as $row) {
			$id = (string)($row['id'] ?? '');
			if ($id !== '') {
				$known[$id] = true;
			}
		}

		if ($ids === []) {
			return array_keys($known);
		}

		$out = [];
		foreach (array_unique($ids) as $id) {
			if (!isset($known[$id])) {
				$report->fail($collection, $id, '', '', '', 'Object not found');
				continue;
			}
			$out[] = $id;
		}

		return $out;
	}

	/**
	 * One object's images, per property. Throws when the object cannot be read.
	 *
	 * @param array<string,string> $properties
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function objectImages(string $collection, string $id, array $properties): array
	{
		$object = $this->objectFetcher->fetchObjectFromDisk($collection, $id);

		$images = [];
		foreach ($properties as $name => $field) {
			$images[$name] = $this->images($object, $name, $field);
		}

		return $images;
	}

	/**
	 * The raw image arrays one property holds: the image itself, or each
	 * gallery image. Entries without a `name` are skipped, and so is a gallery
	 * image whose basename an earlier one has: the route looks images up by
	 * name without extension and serves the first match.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function images(ObjectData $object, string $property, string $field): array
	{
		$value = $object->properties->get($property);
		$raw   = $value instanceof PropertyData ? $value->transform() : null;
		if (!is_array($raw) || $raw === []) {
			return [];
		}

		$list = $field === 'gallery' ? $raw : [$raw];

		$images = [];
		$seen   = [];
		foreach ($list as $image) {
			if (!is_array($image) || !is_string($image['name'] ?? null) || $image['name'] === '') {
				continue;
			}
			$basename = pathinfo($image['name'], PATHINFO_FILENAME);
			if (isset($seen[$basename])) {
				continue;
			}
			$seen[$basename] = true;
			$images[]        = $image;
		}

		return $images;
	}

	/** @param array<string,string|int> $params */
	private function warmVariant(WarmReport $report, string $collection, string $id, string $property, ?string $galleryName, string $imageName, array $params, bool $dryRun): void
	{
		try {
			$state = $this->generator->cacheState($collection, $id, $property, $galleryName, $params);
			match ($state) {
				CacheState::Cached      => $report->cached++,
				CacheState::Original    => $report->original++,
				CacheState::Uncacheable => $report->uncacheable++,
				CacheState::Missing     => $dryRun ? $report->wouldMake++ : $this->make($report, $collection, $id, $property, $galleryName, $params),
			};
		} catch (\Throwable $e) {
			$report->fail($collection, $id, $property, $imageName, http_build_query($params), $e->getMessage());
		}
	}

	/** @param array<string,string|int> $params */
	private function make(WarmReport $report, string $collection, string $id, string $property, ?string $galleryName, array $params): int
	{
		$galleryName === null
			? $this->generator->generateImage($collection, $id, $property, $params)
			: $this->generator->generateGalleryImage($collection, $id, $property, $galleryName, $params);

		return ++$report->made;
	}
}
