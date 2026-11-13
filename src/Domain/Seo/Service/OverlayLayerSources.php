<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

/**
 * What the overlay designer's Layers tab offers in its menus: the fields a
 * text layer can show (the Insert field menu), the logos and fonts ImageWorks
 * can draw, and the image properties a photo layer can take from a record.
 * The browser holds no schema logic; it lists what comes back.
 */
readonly class OverlayLayerSources
{
	/**
	 * Field types that cannot fill a text layer: files, lists of things,
	 * structured data and secrets.
	 */
	private const NOT_TEXT = ['card', 'deck', 'depot', 'empty', 'file', 'folder', 'gallery', 'image', 'json', 'password', 'properties', 'secret', 'svg', 'video'];

	/** Field types a photo layer can draw (MarkLayers' markimage). */
	private const IMAGES = ['image', 'gallery'];

	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private CollectionLister $collectionLister,
		private ObjectFetcher $objects,
		private AccessGuard $guard,
		private Config $config,
	) {
	}

	/**
	 * The properties of a collection's schema that can fill text, with dot
	 * paths into cards (`seo.title`). Labels fall back to the property name;
	 * a card's fields are labelled "Card → Field".
	 *
	 * @return list<array{path:string,label:string}>
	 */
	public function fields(string $schemaId): array
	{
		$schema = $this->schema($schemaId);
		if (!$schema instanceof SchemaData) {
			return [];
		}

		$out = [];
		foreach ($schema->properties as $name => $raw) {
			if (!is_array($raw)) {
				continue;
			}
			$name       = (string)$name;
			$definition = PropertyDefinition::fromArray($raw);
			$label      = $this->label($definition, $name);

			if ($this->isCard($definition, $raw)) {
				foreach ($this->cardFields($raw) as $field) {
					$out[] = ['path' => $name . '.' . $field['path'], 'label' => $label . ' → ' . $field['label']];
				}
				continue;
			}

			if (!in_array($definition->resolveType(), self::NOT_TEXT, true) && !$definition->isPassword()) {
				$out[] = ['path' => $name, 'label' => $label];
			}
		}

		return $out;
	}

	/**
	 * The collections the actor may list that hold images, each with its
	 * image properties as a photo layer writes them: `photo` for an image,
	 * `gallery/first` for a gallery's first image.
	 *
	 * @return list<array{id:string,name:string,images:list<array{path:string,label:string}>}>
	 */
	public function imageSources(?Actor $actor): array
	{
		$out = [];
		foreach ($this->collectionLister->listAllCollections() as $collection) {
			if ($actor instanceof Actor && !$this->guard->can($actor, $collection->id, Operation::List)->passes()) {
				continue;
			}

			$images = [];
			foreach ($this->schema($collection->schema)->properties ?? [] as $name => $raw) {
				if (!is_array($raw)) {
					continue;
				}
				$definition = PropertyDefinition::fromArray($raw);
				$type       = $definition->resolveType();
				if (in_array($type, self::IMAGES, true)) {
					$images[] = ['path' => $type === 'gallery' ? $name . '/first' : (string)$name, 'label' => $this->label($definition, (string)$name)];
				}
			}

			if ($images !== []) {
				$out[] = ['id' => $collection->id, 'name' => $collection->name, 'images' => $images];
			}
		}

		usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

		return $out;
	}

	/**
	 * The file names in the watermarks gallery, which a logo layer's
	 * `mark` takes.
	 *
	 * @return list<string>
	 */
	public function logos(): array
	{
		$gallery = $this->config->imageworks['watermarksGallery'] ?? '';

		return is_string($gallery) && $gallery !== '' ? $this->names('gallery', $gallery, 'gallery') : [];
	}

	/**
	 * The font files in the watermark fonts depot, which a text layer's
	 * `marktextfont` takes.
	 *
	 * @return list<string>
	 */
	public function fonts(): array
	{
		$depot = $this->config->imageworks['watermarkFontsDepot'] ?? 'watermark-fonts';
		if (!is_string($depot) || $depot === '') {
			return [];
		}

		$fonts = array_filter($this->names('depot', $depot, 'depot'), static fn (string $name): bool => (bool)preg_match('/\.(ttf|otf)$/i', $name));

		return array_values($fonts);
	}

	/**
	 * A card's sub-schema fields that can fill text (one level: a card in a
	 * card is left out, as the form builder does).
	 *
	 * @param array<array-key,mixed> $raw
	 *
	 * @return list<array{path:string,label:string}>
	 */
	private function cardFields(array $raw): array
	{
		/** @var array<string,mixed> $raw */
		$ref = PropertyDefinition::extractSchemaRef($raw);
		if ($ref === null) {
			return [];
		}

		$out = [];
		foreach ($this->schema(SchemaFetcher::extractSchemaId($ref))->properties ?? [] as $name => $sub) {
			if (!is_array($sub) || $name === 'id') {
				continue;
			}
			$definition = PropertyDefinition::fromArray($sub);
			if (!$this->isCard($definition, $sub) && !in_array($definition->resolveType(), self::NOT_TEXT, true) && !$definition->isPassword()) {
				$out[] = ['path' => (string)$name, 'label' => $this->label($definition, (string)$name)];
			}
		}

		return $out;
	}

	/**
	 * The `name` of every file a gallery or depot object holds. A depot keeps
	 * its files under `files`; a gallery is the list itself.
	 *
	 * @return list<string>
	 */
	private function names(string $collection, string $id, string $property): array
	{
		try {
			$value = $this->objects->fetchObject($collection, $id)->toArray()[$property] ?? [];
		} catch (\Throwable) {
			return [];
		}

		$files = is_array($value) && is_array($value['files'] ?? null) ? $value['files'] : $value;
		$names = [];
		foreach (is_array($files) ? $files : [] as $file) {
			if (is_array($file) && is_string($file['name'] ?? null) && $file['name'] !== '') {
				$names[] = $file['name'];
			}
		}

		return $names;
	}

	private function schema(string $id): ?SchemaData
	{
		if ($id === '') {
			return null;
		}

		try {
			return $this->schemaFetcher->fetchSchema($id);
		} catch (\Throwable) {
			return null;
		}
	}

	/** @param array<array-key,mixed> $raw */
	private function isCard(PropertyDefinition $definition, array $raw): bool
	{
		/** @var array<string,mixed> $raw */
		return $definition->resolveType() === 'card' || $definition->field === 'card' || ($definition->ref === SchemaData::PROPERTY_TYPE_TO_REF['card'] && PropertyDefinition::extractSchemaRef($raw) !== null);
	}

	private function label(PropertyDefinition $definition, string $name): string
	{
		return $definition->label !== '' ? $definition->label : $name;
	}
}
