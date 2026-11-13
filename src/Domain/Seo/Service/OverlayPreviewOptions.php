<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Seo\Data\OverlayPresets;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Support\Config;

/**
 * What the overlay designer's record picker and presets need: the
 * collections a share card can come from, the records in one of them that
 * the user may read, a default record, and the presets.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") It reads collections,
 * indexes, access, the edition and the watermarks gallery; most of the count
 * is data objects.
 */
readonly class OverlayPreviewOptions
{
	/** Records the picker lists, newest first. */
	public const OBJECTS_MAX = 200;
	/** Records tried for a default that has a share image. */
	private const DEFAULT_TRIES = 10;

	public function __construct(
		private SeoContextFactory $contexts,
		private MetaBuilder $metaBuilder,
		private ObjectFetcher $objects,
		private IndexReader $indexReader,
		private CollectionLister $collectionLister,
		private BuilderConfigService $builderConfig,
		private AccessGuard $guard,
		private EditionFeatureService $editions,
		private Config $config,
	) {
	}

	/**
	 * What the record picker and the presets need: the collections a share
	 * card can come from, the records in one of them, and a default record.
	 * A null actor (auth off) sees every collection and record. `layers`
	 * says whether this edition draws overlays at all (Pro and above).
	 *
	 * @return array{layers:bool,collections:list<array{id:string,name:string}>,collection:string,objects:list<array{id:string,title:string,draft:bool}>,default:string,ownOverlay:bool,presets:list<array{id:string,overlay:array<string,mixed>}>}
	 */
	public function for(?Actor $actor, string $collection, string $query = ''): array
	{
		$collections = $this->collections($actor, $collection);
		$byId        = array_column(array_map(static fn (CollectionData $c): array => ['id' => $c->id, 'meta' => $c], $collections), 'meta', 'id');
		$collection  = isset($byId[$collection]) ? $collection : (string)(array_key_first($byId) ?? '');
		$meta        = $byId[$collection] ?? null;
		$objects     = $collection === '' ? [] : $this->objectsOf($actor, $collection, $query);

		return [
			'layers'      => $this->editions->can(EditionFeature::IMAGE_LAYERS),
			'collections' => array_map(static fn (CollectionData $c): array => ['id' => $c->id, 'name' => $c->name], $collections),
			'collection'  => $collection,
			'objects'     => $objects,
			'default'     => $this->defaultObject($collection, $objects),
			'ownOverlay'  => $meta instanceof CollectionData && SocialImageBuilder::normalized(SeoSettings::json($meta->seo['imageOptions'] ?? null)) !== null,
			'presets'     => OverlayPresets::all($this->firstLogo()),
		];
	}

	/**
	 * The Site Builder pages first, then every collection with a URL, that
	 * the actor may list. The collection asked for is kept even without a
	 * URL: its own settings preview its own overlay.
	 *
	 * @return list<CollectionData>
	 */
	private function collections(?Actor $actor, string $requested): array
	{
		$pagesId = $this->builderConfig->getPagesCollectionId();
		$out     = [];

		foreach ($this->collectionLister->listAllCollections() as $collection) {
			$wanted = $collection->id === $pagesId || $collection->url !== '' || $collection->id === $requested;
			if ($wanted && ($actor === null || $this->guard->can($actor, $collection->id, Operation::List)->passes())) {
				$out[] = $collection;
			}
		}

		usort($out, static fn (CollectionData $a, CollectionData $b): int => [$a->id !== $pagesId, $a->name] <=> [$b->id !== $pagesId, $b->name]);

		return $out;
	}

	/**
	 * The records the actor may read, newest first, at most OBJECTS_MAX,
	 * narrowed by a search on the id and title.
	 *
	 * @return list<array{id:string,title:string,draft:bool}>
	 */
	private function objectsOf(?Actor $actor, string $collection, string $query): array
	{
		try {
			$entries = $this->indexReader->fetchIndex($collection)->objects->all();
		} catch (\Throwable) {
			return [];
		}

		$entries = array_values(array_filter($entries, is_array(...)));
		if ($actor !== null) {
			$entries = $this->guard->readScope($actor, $collection)->filter($entries);
		}
		usort($entries, static fn (array $a, array $b): int => self::stamp($b) <=> self::stamp($a));

		$needle = mb_strtolower(trim($query));
		$out    = [];
		foreach ($entries as $entry) {
			$id    = is_scalar($entry['id'] ?? null) ? (string)$entry['id'] : '';
			$title = self::titleOf($entry, $id);
			if ($id === '' || ($needle !== '' && !str_contains(mb_strtolower($id . ' ' . $title), $needle))) {
				continue;
			}

			$out[] = ['id' => $id, 'title' => $title, 'draft' => (bool)($entry['draft'] ?? false)];
			if (count($out) >= self::OBJECTS_MAX) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The newest published record with a share image, else the first listed.
	 *
	 * @param list<array{id:string,title:string,draft:bool}> $objects
	 */
	private function defaultObject(string $collection, array $objects): string
	{
		$published = array_values(array_filter($objects, static fn (array $o): bool => !$o['draft']));

		foreach (array_slice($published, 0, self::DEFAULT_TRIES) as $object) {
			try {
				$ctx = $this->contexts->make($this->objects->fetchObject($collection, $object['id'])->toArray(), ['collection' => $collection]);
				if ($this->metaBuilder->build($ctx)->ogImage !== '') {
					return $object['id'];
				}
			} catch (\Throwable) {
				continue;
			}
		}

		return $objects[0]['id'] ?? '';
	}

	/** The first file in the watermarks gallery, for the logo preset. */
	private function firstLogo(): string
	{
		$gallery = $this->config->imageworks['watermarksGallery'] ?? '';
		if (!is_string($gallery) || $gallery === '') {
			return '';
		}

		try {
			$images = $this->objects->fetchObject('gallery', $gallery)->toArray()['gallery'] ?? [];
		} catch (\Throwable) {
			return '';
		}

		$first = is_array($images) ? (array_values($images)[0] ?? null) : null;

		return is_array($first) && is_string($first['name'] ?? null) ? $first['name'] : '';
	}

	/** @param array<array-key,mixed> $entry */
	private static function stamp(array $entry): string
	{
		$stamp = $entry['updated'] ?? $entry['created'] ?? '';

		return is_scalar($stamp) ? (string)$stamp : '';
	}

	/** @param array<array-key,mixed> $entry */
	private static function titleOf(array $entry, string $id): string
	{
		foreach (['title', 'name', 'label'] as $key) {
			if (is_string($entry[$key] ?? null) && trim($entry[$key]) !== '') {
				return trim($entry[$key]);
			}
		}

		return $id;
	}
}
