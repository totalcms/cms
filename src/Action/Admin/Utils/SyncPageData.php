<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Playground\Data\PlaygroundData;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Sync\Data\SyncableCollections;
use TotalCMS\Domain\Template\Service\TemplateLister;

/** The Sync utility's pickers: settings, schemas, templates, collections, seed objects. */
final readonly class SyncPageData implements UtilsPageData
{
	public function __construct(
		private BuilderTemplatePaths $builderTemplatePaths,
		private CollectionLister $collectionLister,
		private CollectionFetcher $collectionFetcher,
		private IndexReader $indexReader,
		private SettingsFetcher $settingsFetcher,
		private SchemaLister $schemaLister,
		private TemplateLister $templateLister,
	) {
	}

	public function build(ServerRequestInterface $request, string $page, string $action): array
	{
		// Git-managed sites deliver templates via git, not sync — the
		// SyncService filter silently drops them from every push/pull, so
		// offering the checkboxes would be a picker whose selections do
		// nothing. Hide the section and let the template say why.
		$templatesGitManaged = $this->builderTemplatePaths->isProjectManaged();

		// Every local collection is offered for SETTINGS sync (the meta —
		// url, MCP card, sitemap, overrides — never objects or counters).
		// The Twig Playground is the exception: it is a per-install
		// scratchpad that JumpStartExporter now drops from every sync, so
		// listing it would be a checkbox whose selection does nothing —
		// the same reason git-managed templates are hidden above.
		$collectionMeta = [];
		foreach ($this->collectionLister->listAllCollections() as $collection) {
			if ($collection->id === PlaygroundData::COLLECTION_ID) {
				continue;
			}

			$collectionMeta[] = [
				'id'   => $collection->id,
				'name' => $collection->name !== '' ? $collection->name : ucfirst($collection->id),
			];
		}
		usort($collectionMeta, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

		// Collections whose OBJECTS the Seed Objects section may offer.
		// Narrower than the settings list above: seedableInUi() drops the
		// five with their own sections, the binary-only collections (the
		// binary IS the object there, so a seeded row points at a file the
		// target does not have), the playground scratchpad, and `auth`.
		// The object count rides along so nobody blind-ticks a
		// five-thousand-row collection.
		$seedCollections = [];
		foreach ($this->collectionLister->listAllCollections() as $collection) {
			if (!SyncableCollections::seedableInUi($collection->id)) {
				continue;
			}

			$seedCollections[] = [
				'id'    => $collection->id,
				'name'  => $collection->name !== '' ? $collection->name : ucfirst($collection->id),
				'count' => max($collection->totalObjects, 0),
			];
		}
		usort($seedCollections, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

		return [
			'syncData' => [
				'settings'            => $this->settingsFetcher->loadSection('sync'),
				'schemas'             => $this->schemaLister->listCustomSchemas(),
				'templates'           => $templatesGitManaged ? [] : $this->templateLister->listBuilderTemplates(null, true),
				'templatesGitManaged' => $templatesGitManaged,
				'collections'         => $this->resolveSyncableCollections(),
				'collectionMeta'      => $collectionMeta,
				'seedCollections'     => $seedCollections,
			],
		];
	}

	/**
	 * Return the sync-allowlisted collections that actually exist on this
	 * site along with their selectable object ids, shaped for sync.twig.
	 *
	 * Each entry: {id, name, objects: [{id, label}]}.
	 * Labels prefer human-friendly fields from the index (title, name,
	 * subject, route) and fall back to the object id.
	 *
	 * @return list<array{id:string,name:string,objects:list<array{id:string,label:string}>}>
	 */
	private function resolveSyncableCollections(): array
	{
		$out = [];
		foreach (SyncableCollections::IDS as $id) {
			$collection = $this->collectionFetcher->fetchCollection($id);
			if (!$collection instanceof CollectionData) {
				continue;
			}

			try {
				$index = $this->indexReader->fetchIndex($id);
			} catch (\Throwable) {
				$index = null;
			}

			$objects = [];
			if ($index instanceof IndexData) {
				foreach ($index->objects as $entry) {
					$objectId = (string)($entry['id'] ?? '');
					if ($objectId === '') {
						continue;
					}
					$objects[] = [
						'id'    => $objectId,
						'label' => $this->labelForIndexEntry($entry, $objectId),
					];
				}
			}

			$out[] = [
				'id'      => $id,
				'name'    => $collection->name !== '' ? $collection->name : $id,
				'objects' => $objects,
			];
		}

		return $out;
	}

	/**
	 * Pick a human label for an index entry by walking common display
	 * fields. Falls back to the object id so the UI always has something
	 * to render.
	 *
	 * `route` comes first deliberately. Of the syncable collections only
	 * builder-pages carries one, and for a page the URL path identifies the
	 * record better than its title does: paths are unique and stable, whereas
	 * two pages can share a title (localized variants especially — "About" and
	 * "Über Uns" both live at telling paths). Every other syncable collection
	 * (mailer, automations, mcp-prompt, dataviews) has no `route`, so they keep
	 * falling through to title/name/subject exactly as before.
	 *
	 * @param array<string,mixed> $entry
	 */
	private function labelForIndexEntry(array $entry, string $fallback): string
	{
		foreach (['route', 'title', 'name', 'subject', 'label'] as $field) {
			$value = $entry[$field] ?? null;
			if (is_string($value) && trim($value) !== '') {
				return $value;
			}
		}

		return $fallback;
	}
}
