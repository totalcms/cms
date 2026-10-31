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
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Pre-builds the ImageWorks cache for a collection: for every image, gallery
 * image, video poster and named card or deck image, every variant
 * `WarmVariants` asks for is checked with
 * `ImageGenerator::cacheState()` and, when missing, made through the same
 * `generate*()` entry point the public route uses, so both land on one file.
 *
 * `ImageGenerator` keeps per-call state in properties (collection, id,
 * params…), so its calls must stay sequential — do not parallelize them
 * over one instance.
 */
final readonly class ImageWorksWarmer
{
	/**
	 * Top-level fields the command warms when no property is named. A video
	 * poster is warmed only when named: templates usually ask for one poster
	 * URL, and a grid run would encode every width of it for nothing.
	 */
	private const FIELDS = ['image', 'gallery'];

	/** Fields that hold nested images, addressed as `parent/child`. */
	private const CONTAINERS = ['card' => 'card', 'deck' => 'deck', 'deckTable' => 'deck'];

	/** Child fields a card or deck can hold that the route can serve. */
	private const CHILD_FIELDS = ['image', 'video'];

	public function __construct(
		private ImageGenerator $generator,
		private SchemaFetcher $schemaFetcher,
		private IndexReader $indexReader,
		private ObjectFetcher $objectFetcher,
		private EditionFeatureService $editionFeatures,
	) {
	}

	/**
	 * What to warm, keyed by the name the operator gave: the collection's
	 * image and gallery properties, or the one named (a video too). A card or deck
	 * image is named `parent/child` — `mycard/photo` is that card's photo,
	 * `mydeck/photo` the photo of every deck item. A video child warms its
	 * poster.
	 *
	 * @return array<string,array{root:string,field:string,child:?string,childField:?string}>
	 *
	 * @throws InvalidArgumentException
	 */
	public function properties(string $collection, ?string $property): array
	{
		$definitions = $this->schemaFetcher->fetchSchemaForCollection($collection)->properties;

		if ($property !== null) {
			return [$property => $this->target($collection, $definitions, $property)];
		}

		$found = [];
		foreach ($definitions as $name => $definition) {
			$field = is_array($definition) ? ($definition['field'] ?? null) : null;
			if (in_array($field, self::FIELDS, true)) {
				$found[(string)$name] = ['root' => (string)$name, 'field' => $field, 'child' => null, 'childField' => null];
			}
		}

		if ($found === []) {
			throw new InvalidArgumentException("Collection {$collection} has no image or gallery properties.");
		}

		return $found;
	}

	/**
	 * One named target, checked against the schema.
	 *
	 * @param array<int|string,mixed> $definitions
	 *
	 * @return array{root:string,field:string,child:?string,childField:?string}
	 *
	 * @throws InvalidArgumentException
	 */
	private function target(string $collection, array $definitions, string $property): array
	{
		$parts = explode('/', $property);
		if (count($parts) > 2 || in_array('', $parts, true)) {
			throw new InvalidArgumentException("Property {$property} is not a valid name. Use a property, or parent/child for an image in a card or deck.");
		}
		[$root, $child] = [$parts[0], $parts[1] ?? null];

		if (!array_key_exists($root, $definitions)) {
			throw new InvalidArgumentException("Property {$root} is not in collection {$collection}.");
		}
		$definition = $definitions[$root];
		$field      = is_array($definition) && is_string($definition['field'] ?? null) ? $definition['field'] : '';

		if ($field === 'video') {
			if (!in_array($child, [null, 'poster'], true)) {
				throw new InvalidArgumentException("{$root} is a video. Name it as {$root} or {$root}/poster to warm its poster.");
			}

			return ['root' => $root, 'field' => 'video', 'child' => null, 'childField' => null];
		}

		if (in_array($field, self::FIELDS, true)) {
			if ($child !== null) {
				throw new InvalidArgumentException("{$root} has no fields inside it. Name it alone: {$root}.");
			}

			return ['root' => $root, 'field' => $field, 'child' => null, 'childField' => null];
		}

		if (isset(self::CONTAINERS[$field])) {
			/** @var array<string,mixed> $definition */
			$children = $this->imageChildren($root, $definition);
			if ($child === null) {
				$names = implode(', ', array_map(static fn (string $c): string => "{$root}/{$c}", array_keys($children)));

				throw new InvalidArgumentException("{$root} is a {$field}. Name the image inside it as parent/child" . ($names === '' ? ', but it has no image or video fields.' : ": {$names}."));
			}
			if (!isset($children[$child])) {
				throw new InvalidArgumentException("{$child} is not an image or video field in {$root}.");
			}

			return ['root' => $root, 'field' => self::CONTAINERS[$field], 'child' => $child, 'childField' => $children[$child]];
		}

		throw new InvalidArgumentException("Property {$property} in collection {$collection} is not an image, gallery, video, card or deck field.");
	}

	/**
	 * The image and video fields of the schema a card or deck refers to.
	 *
	 * @param array<string,mixed> $definition
	 *
	 * @return array<string,string> name => field
	 *
	 * @throws InvalidArgumentException
	 */
	private function imageChildren(string $root, array $definition): array
	{
		$ref = PropertyDefinition::extractSchemaRef($definition);
		if ($ref === null || $ref === '') {
			return [];
		}

		$schemaId = SchemaFetcher::extractSchemaId($ref);
		try {
			$schema = $this->schemaFetcher->fetchSchema($schemaId);
		} catch (\Throwable) {
			throw new InvalidArgumentException("{$root} uses the schema {$schemaId}, which could not be read.");
		}

		$children = [];
		foreach ($schema->properties as $name => $child) {
			$field = is_array($child) ? ($child['field'] ?? null) : null;
			if (in_array($field, self::CHILD_FIELDS, true)) {
				$children[(string)$name] = $field;
			}
		}

		return $children;
	}

	/**
	 * The first property with a watermark this edition can't apply, or null.
	 * The route strips such a watermark, so warming it would only fill the
	 * cache with files the site never serves. Object overrides count too.
	 *
	 * @param array<string,array{root:string,field:string,child:?string,childField:?string}> $properties from properties()
	 * @param list<string> $ids only these objects; [] for the whole collection
	 */
	public function blockedWatermark(string $collection, array $properties, array $ids = []): ?string
	{
		$image = $this->editionFeatures->can(EditionFeature::IMAGE_WATERMARKS);
		$text  = $this->editionFeatures->can(EditionFeature::TEXT_WATERMARKS);
		if ($image && $text) {
			return null;
		}

		$objects = ['', ...$this->objectIds($collection, $ids, new WarmReport())];
		// A nested image takes the watermark of the property it lives in.
		foreach ($properties as $name => $target) {
			foreach ($objects as $id) {
				$mark = $this->generator->watermarkSettings($collection, $target['root'], $id);
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
				$root   = $properties[$name]['root'];
				$nested = in_array($properties[$name]['field'], ['card', 'deck', 'video'], true);
				foreach ($list as ['image' => $image, 'path' => $path]) {
					$report->images++;
					// A nested image's failure row names where it lives: mydeck/item-3/photo.
					$where = $nested ? "{$root}/{$path}" : $root;
					foreach ($variants->forImage($image) as $params) {
						$this->warmVariant($report, $collection, $id, $root, $path, $where, (string)$image['name'], $params, $dryRun);
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
	 * @param array<string,array{root:string,field:string,child:?string,childField:?string}> $properties
	 *
	 * @return array<string,list<array{image:array<string,mixed>,path:?string}>>
	 */
	private function objectImages(string $collection, string $id, array $properties): array
	{
		$object = $this->objectFetcher->fetchObjectFromDisk($collection, $id);

		$images = [];
		foreach ($properties as $name => $target) {
			$images[$name] = $this->images($object, $target);
		}

		return $images;
	}

	/**
	 * The raw images one target holds, each with the `{path}` the route
	 * addresses it by: null for an image property, the name without
	 * extension for a gallery image, `poster` for a video, and the path
	 * through the card or deck for a nested image (`photo`, `item-3/photo`,
	 * `item-3/promo/poster`). Entries without a `name` are skipped, and so is
	 * a gallery image whose basename an earlier one has: the route looks
	 * images up by name without extension and serves the first match.
	 *
	 * @param array{root:string,field:string,child:?string,childField:?string} $target
	 *
	 * @return list<array{image:array<string,mixed>,path:?string}>
	 */
	private function images(ObjectData $object, array $target): array
	{
		$value = $object->properties->get($target['root']);
		$raw   = $value instanceof PropertyData ? $value->transform() : null;
		if (!is_array($raw) || $raw === []) {
			return [];
		}

		$found = match ($target['field']) {
			'gallery' => $this->galleryImages($raw),
			'video'   => [[$raw['poster'] ?? null, 'poster']],
			'card'    => [$this->childImage($raw, (string)$target['child'], $target['childField'], '')],
			'deck'    => array_map(
				fn (int|string $item): array => $this->childImage(is_array($raw[$item]) ? $raw[$item] : [], (string)$target['child'], $target['childField'], $item . '/'),
				array_keys(array_filter($raw, is_array(...))),
			),
			default   => [[$raw, null]],
		};

		$images = [];
		foreach ($found as [$image, $path]) {
			if (is_array($image) && is_string($image['name'] ?? null) && $image['name'] !== '') {
				$images[] = ['image' => $image, 'path' => $path];
			}
		}

		return $images;
	}

	/**
	 * @param array<int|string,mixed> $raw
	 *
	 * @return list<array{mixed,string}>
	 */
	private function galleryImages(array $raw): array
	{
		$found = [];
		$seen  = [];
		foreach ($raw as $image) {
			if (!is_array($image) || !is_string($image['name'] ?? null) || $image['name'] === '') {
				continue;
			}
			$basename = pathinfo($image['name'], PATHINFO_FILENAME);
			if (isset($seen[$basename])) {
				continue;
			}
			$seen[$basename] = true;
			$found[]         = [$image, $basename];
		}

		return $found;
	}

	/**
	 * One card's (or deck item's) child image and its route path. A video
	 * child yields its poster.
	 *
	 * @param array<int|string,mixed> $container
	 *
	 * @return array{mixed,string}
	 */
	private function childImage(array $container, string $child, ?string $childField, string $prefix): array
	{
		$value = $container[$child] ?? null;
		if ($childField === 'video') {
			return [is_array($value) ? ($value['poster'] ?? null) : null, "{$prefix}{$child}/poster"];
		}

		return [$value, $prefix . $child];
	}

	/** @param array<string,string|int> $params */
	private function warmVariant(WarmReport $report, string $collection, string $id, string $property, ?string $path, string $where, string $imageName, array $params, bool $dryRun): void
	{
		try {
			$state = $this->generator->cacheState($collection, $id, $property, $path, $params);
			match ($state) {
				CacheState::Cached      => $report->cached++,
				CacheState::Original    => $report->original++,
				CacheState::Uncacheable => $report->uncacheable++,
				CacheState::Missing     => $dryRun ? $report->wouldMake++ : $this->make($report, $collection, $id, $property, $path, $params),
			};
		} catch (\Throwable $e) {
			$report->fail($collection, $id, $where, $imageName, http_build_query($params), $e->getMessage());
		}
	}

	/** @param array<string,string|int> $params */
	private function make(WarmReport $report, string $collection, string $id, string $property, ?string $path, array $params): int
	{
		$path === null
			? $this->generator->generateImage($collection, $id, $property, $params)
			: $this->generator->generateGalleryImage($collection, $id, $property, $path, $params);

		return ++$report->made;
	}
}
