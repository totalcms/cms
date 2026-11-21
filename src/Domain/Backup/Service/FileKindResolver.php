<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Backup\Service;

use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Says what the files in one folder of a property are: an `image`, a `file`,
 * a `gallery` picture or a `depot` entry.
 *
 * The file history asks this of every file it is about to keep, because what
 * a file is decides the rule it is kept under. The answer comes from the
 * schema and from where the folder sits under the property:
 *
 *   cover/                  an image field           → image
 *   pdf/                    a file field             → file
 *   photos/                 a gallery                → gallery
 *   downloads/Price Lists/  a depot, any folder deep → depot
 *   promo/poster/           a video's poster         → image
 *   lead/photo/             a card's child           → that child's kind
 *   team/alice/photo/       a deck item's child      → that child's kind
 *
 * The walk is the one SaverFactory makes to find the saver for a nested
 * upload, so a file is kept as exactly what it was uploaded as. Anything else
 * (a text field, the top of a card, a child the schema does not have) is null:
 * nothing keeps files there, so nothing is kept from there.
 */
class FileKindResolver
{
	public const IMAGE = 'image';

	public const FILE = 'file';

	public const GALLERY = 'gallery';

	public const DEPOT = 'depot';

	public function __construct(
		private readonly SchemaFetcher $schemas,
		private readonly PropertyMetaResolver $meta,
	) {
	}

	/**
	 * @param string $dir the folder under the property, `''` for the property's own
	 *
	 * @return self::IMAGE|self::FILE|self::GALLERY|self::DEPOT|null
	 */
	public function kind(string $collection, string $property, string $dir): ?string
	{
		try {
			$definition = $this->schemas->fetchSchemaForCollection($collection)->properties[$property] ?? null;
			if (!is_array($definition)) {
				return null;
			}

			$type     = PropertyDefinition::fromArray($definition)->resolveType();
			$segments = $dir === '' ? [] : explode('/', $dir);

			// A depot owns every folder under it; a gallery has none.
			if ($type === self::DEPOT) {
				return self::DEPOT;
			}
			if ($type === self::GALLERY) {
				return $segments === [] ? self::GALLERY : null;
			}

			if ($type === 'deck') {
				if ($segments === []) {
					return null;
				}
				array_shift($segments); // the item id
			}

			foreach ($segments as $segment) {
				$type = match ($type) {
					'video'        => $segment === 'poster' ? self::IMAGE : null,
					'card', 'deck' => PropertyDefinition::fromArray($this->meta->resolveNested($collection, $property, $segment))->resolveType(),
					default        => null,
				};
				if ($type === null) {
					return null;
				}
			}

			return $type === self::IMAGE || $type === self::FILE ? $type : null;
		} catch (\Throwable) {
			// A schema that cannot be read keeps nothing; it must not fail a save.
			return null;
		}
	}
}
