<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Form;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Feed\Service\PodcastCategories;
use TotalCMS\Domain\Locale\LocaleRegistry;
use TotalCMS\Domain\Template\Service\TemplateLister;

/**
 * The option lists a form hands its fields.
 *
 * A field's `propertyOptions` names a source — `collections`, `pages`,
 * `views`, `locales`, `podcastCategories`, a schema's property keys, the
 * distinct values of a property across a collection — and the field asks its
 * form, which asks here. Every list is a function of the shared services and
 * the arguments named on the call; nothing reads form state. The TotalForm
 * methods of the same names are thin pass-throughs that fill in the form's
 * own collection where a caller leaves it out.
 */
final class FormOptionSources
{
	public function __construct(private readonly FormServices $services)
	{
	}

	/**
	 * The distinct, non-empty values of one property across a collection's index.
	 *
	 * @return array<int|string,mixed>
	 */
	public function propertyListForCollection(string $property, string $collection): array
	{
		$index = $this->services->collectionReader->fetchIndex($collection);

		// array_filter removes any empty values
		return array_filter($index->objects->pluck($property)->flatten()->unique()->toArray());
	}

	/**
	 * @return array<string>
	 */
	public function categoryListForCollections(): array
	{
		return $this->services->collectionLister->listCategories();
	}

	/**
	 * @return array<string>
	 */
	public function categoryListForSchemas(): array
	{
		return $this->services->schemaLister->listCategories();
	}

	/**
	 * @return array<string>
	 */
	public function collectionIdList(): array
	{
		$collections = $this->services->collectionLister->listAllCollections();

		return array_values(array_map(fn (CollectionData $c): string => $c->id, $collections));
	}

	/**
	 * @return array<int,array{value: string, label: string}>
	 */
	public function collectionIdListWithLabels(): array
	{
		$collections = $this->services->collectionLister->listAllCollections();

		return array_values(array_map(fn (CollectionData $c): array => [
			'value' => $c->id,
			'label' => $c->name !== '' ? $c->name : $c->id,
		], $collections));
	}

	/**
	 * @return array<int,array{value: string, label: string}>
	 */
	public function pageCollectionOptions(): array
	{
		return $this->collectionsUsingSchema(BuilderConfigService::DEFAULT_SCHEMA_ID);
	}

	/**
	 * @return list<array{value:string,label:string}>
	 */
	public function collectionsUsingSchema(string $schemaId): array
	{
		$options = [];

		foreach ($this->services->collectionLister->listAllCollections() as $collection) {
			if (!$this->schemaIsOrInherits($collection->schema, $schemaId)) {
				continue;
			}

			$options[] = [
				'value' => $collection->id,
				'label' => $collection->name !== '' ? $collection->name : $collection->id,
			];
		}

		return $options;
	}

	private function schemaIsOrInherits(string $schemaId, string $target): bool
	{
		if ($schemaId === $target) {
			return true;
		}

		try {
			$schema = $this->services->schemaFetcher->fetchRawSchema($schemaId);
		} catch (\Throwable) {
			return false;
		}

		return in_array($target, $schema->inheritFrom, true);
	}

	/**
	 * @return list<array{value: string, label: string}>
	 */
	public function adminNavItemList(): array
	{
		return $this->services->navRegistry?->options() ?? [];
	}

	/**
	 * @return array<string>
	 */
	public function viewIdList(): array
	{
		if (!$this->services->dataViewLister instanceof DataViewLister) {
			return [];
		}

		$ids = [];
		foreach ($this->services->dataViewLister->listViews() as $view) {
			if (is_array($view) && isset($view['id']) && is_string($view['id'])) {
				$ids[] = $view['id'];
			}
		}

		return $ids;
	}

	/**
	 * @return array<int,array{value: string, label: string}>
	 */
	public function viewIdListWithLabels(): array
	{
		if (!$this->services->dataViewLister instanceof DataViewLister) {
			return [];
		}

		$views = [];
		foreach ($this->services->dataViewLister->listViews() as $view) {
			if (!is_array($view) || !isset($view['id']) || !is_string($view['id'])) {
				continue;
			}
			$name    = isset($view['name']) && is_string($view['name']) && $view['name'] !== '' ? $view['name'] : $view['id'];
			$views[] = ['value' => $view['id'], 'label' => $name];
		}

		return $views;
	}

	/**
	 * @return array<string,array<int,array{value: string, label: string}>>
	 */
	public function collectionAndViewOptions(): array
	{
		// Not strnatcasecmp: its case folding follows LC_CTYPE, so a collection
		// or view named in a non-Latin script sorted differently depending on
		// the server's locale. See FormField::compareLabels().
		$byLabel = (static fn (array $a, array $b): int => strnatcmp(
			mb_strtolower($a['label'], 'UTF-8'),
			mb_strtolower($b['label'], 'UTF-8'),
		));

		$groups      = [];
		$collections = $this->collectionIdListWithLabels();
		if ($collections !== []) {
			usort($collections, $byLabel);
			$groups['Collections'] = $collections;
		}
		$views = $this->viewIdListWithLabels();
		if ($views !== []) {
			usort($views, $byLabel);
			$groups['Data Views'] = $views;
		}

		return $groups;
	}

	/**
	 * @return array<string, list<array{value: string, label: string}>>
	 */
	public static function podcastCategoryOptions(): array
	{
		$groups = [];
		foreach (PodcastCategories::TAXONOMY as $parent => $children) {
			$options = [['value' => $parent, 'label' => $parent]];
			foreach ($children as $child) {
				$options[] = ['value' => $parent . ' > ' . $child, 'label' => $child];
			}
			$groups[$parent] = $options;
		}

		return $groups;
	}

	/**
	 * @return array<string>
	 */
	public function layoutListForBuilder(): array
	{
		if (!$this->services->templateLister instanceof TemplateLister) {
			return [];
		}

		return $this->services->templateLister->listBuilderTemplates('layouts', true);
	}

	/**
	 * @return array<string>
	 */
	public function pageListForBuilder(): array
	{
		if (!$this->services->templateLister instanceof TemplateLister) {
			return [];
		}

		return $this->services->templateLister->listBuilderTemplates('pages', true);
	}

	/**
	 * @return array<string>
	 */
	public function pageMiddlewareList(): array
	{
		if (!$this->services->pageMiddlewareRegistry instanceof PageMiddlewareRegistry) {
			return [];
		}

		return $this->services->pageMiddlewareRegistry->availableNames();
	}

	/**
	 * @return array<string>
	 */
	public function schemaPropertyKeys(string $collection = '', string $schema = ''): array
	{
		try {
			if ($collection !== '') {
				return array_keys($this->services->schemaFetcher->fetchSchemaForCollection($collection)->properties);
			}

			if ($schema !== '') {
				return array_keys($this->services->schemaFetcher->fetchSchema($schema)->properties);
			}
		} catch (\Throwable) {
			return [];
		}

		return [];
	}

	/**
	 * @param array<string>        $properties Properties to fetch
	 * @param string               $collection Collection name (defaults to current collection)
	 * @param array<string,string> $filters    Optional include/exclude filters
	 * @return array<mixed>
	 */
	public function propertiesForCollection(array $properties, string $collection, array $filters = []): array
	{
		if ($filters !== []) {
			$objects = $this->services->indexFilter->fetchFilteredIndex($collection, $filters);
		} else {
			$objects = $this->services->collectionReader->fetchIndex($collection)->objects->toArray();
		}

		return array_map(static fn (mixed $item): array => collect(is_array($item) ? $item : [])->only($properties)->toArray(), $objects);
	}

	/**
	 * @param array<string>        $properties Properties to fetch
	 * @param string               $viewId     DataView ID
	 * @param array<string,string> $filters    Optional include/exclude filters
	 * @return array<mixed>
	 */
	public function propertiesForView(array $properties, string $viewId, array $filters = []): array
	{
		$data = $this->services->dataViewFilter->fetchFilteredViewData($viewId, $filters);

		return array_map(static fn (mixed $item): array => collect(is_array($item) ? $item : [])->only($properties)->toArray(), $data);
	}

	/**
	 * @return array<array<string,string>>
	 */
	public function accessGroupOptionsForField(): array
	{
		$groups = $this->services->accessGroupLister->listAll();

		return array_map(fn (AccessGroupData $group): array => [
			'value' => $group->id,
			'label' => $group->id,
		], $groups);
	}

	/**
	 * @return array<int,string>
	 */
	public function mediaTagsForCollection(string $field, string $type, string $collection): array
	{
		$index = $this->services->collectionReader->fetchIndex($collection);

		return TotalForm::extractMediaTags($index->objects->all(), $field, $type);
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	public function getLocales(): array
	{
		return $this->services->config->i18n['available'];
	}

	public function getDefaultLocale(): string
	{
		return $this->services->config->i18n['default'];
	}

	/**
	 * @return array<int,array{value: string, label: string}>
	 */
	public function localeList(): array
	{
		return LocaleRegistry::options();
	}

	/**
	 * @return array<int,array{value: string, label: string}>
	 */
	public function eventsList(): array
	{
		return CoreEvent::options();
	}
}
