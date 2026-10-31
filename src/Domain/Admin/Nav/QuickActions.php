<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Nav;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter;
use TotalCMS\Support\Config;

/**
 * The dashboard's Quick Actions: a fixed catalog that the
 * `dashboard.quickActions` setting picks from and orders. Each action is a
 * {@see NavEntry}, so it is checked with the sidebar's own auth and edition
 * rules ({@see AdminNavRegistry::allows()}).
 *
 * Each collection adds a "New …" action too, saved as
 * `new-object:{collection}`. It is shown to users who may create objects in
 * that collection on this edition, and skipped once the collection is gone.
 *
 * Quick Navigation lists every "New …" action the user may use
 * ({@see quickNavItems()}), so typing "New User" opens the add form.
 */
final readonly class QuickActions
{
	public const SETTING = 'quickActions';

	/** Id prefix of the per-collection "New …" actions. */
	public const NEW_OBJECT = 'new-object:';

	/**
	 * Collections without a "New …" action: each has its own editor with a
	 * New action in the catalog (or, for playground, none).
	 */
	private const NO_NEW_OBJECT = ['playground', 'mailer', 'dataviews', 'builder-pages', 'automations'];

	/** What the dashboard shows until the setting is saved. */
	public const DEFAULTS = ['new-collection', 'new-schema', 'new-template', 'logs', 'jobqueue', 'cache-manager'];

	public function __construct(
		private AdminNavRegistry $nav,
		private TranslationService $translator,
		private Config $config,
		private AuthTwigAdapter $auth,
		private CollectionLister $collections,
		private CollectionEditionService $collectionEditions,
	) {
	}

	/**
	 * Every action the dashboard can show, before any permission check.
	 * Utilities stay admin-only, as they were before the setting existed.
	 *
	 * @return list<NavEntry>
	 */
	public function items(): array
	{
		$t = fn (string $key): string => $this->translator->trans($key);

		return [
			new NavEntry('new-collection', $t('dashboard.new_collection'), 'collections/new', 'collections:create', null, 'var(--icon-collections)'),
			new NavEntry('new-schema', $t('dashboard.new_schema'), 'schemas/new', 'schemas:create', 'custom_schemas', 'var(--icon-schema)'),
			new NavEntry('new-page', $t('dashboard.new_page'), 'builder/page/add', 'builder', null, 'var(--icon-file)'),
			new NavEntry('new-template', $t('dashboard.new_template'), 'builder/new', 'builder', null, 'var(--icon-templates)'),
			new NavEntry('new-dataview', $t('dashboard.new_dataview'), 'dataviews/new', 'dataviews', 'data_views', 'var(--icon-data-views)'),
			new NavEntry('new-mailer', $t('dashboard.new_mailer'), 'mailer/new', 'mailer', 'mailer_actions', 'var(--icon-mailer)'),
			new NavEntry('new-automation', $t('dashboard.new_automation'), 'automations/new', 'admin', 'automations', 'var(--icon-automations)'),
			new NavEntry('playground', $t('dashboard.twig_playground'), 'playground', 'playground', null, 'var(--icon-twig-playground)'),
			new NavEntry('activity', $t('dashboard.activity_log'), 'utils/activity', 'admin', null, 'var(--icon-time)'),
			new NavEntry('logs', $t('dashboard.log_analyzer'), 'utils/logs', 'admin', null, 'var(--icon-warn)'),
			new NavEntry('jobqueue', $t('dashboard.job_queue_manager'), 'utils/jobqueue', 'admin', null, 'var(--icon-terminal)'),
			new NavEntry('cache-manager', $t('dashboard.cache_manager'), 'utils/cache-manager', 'admin', null, 'var(--icon-clean-o)'),
			new NavEntry('settings', $t('nav.settings'), 'settings', 'admin', null, 'var(--icon-cog)'),
			new NavEntry('docs', $t('dashboard.documentation'), 'docs', 'docs', null, 'var(--icon-docs)'),
		];
	}

	/**
	 * A "New …" action for each collection, ordered by name; its keywords
	 * hold the collection's name. The permission is not a nav permission:
	 * {@see allows()} checks the collection itself.
	 *
	 * @return list<NavEntry>
	 */
	public function collectionItems(): array
	{
		$collections = array_values(array_filter(
			$this->collections->listAllCollections(),
			fn (CollectionData $collection): bool => !in_array($collection->id, self::NO_NEW_OBJECT, true),
		));
		usort($collections, fn (CollectionData $a, CollectionData $b): int => strnatcmp(mb_strtolower($a->name), mb_strtolower($b->name)));

		return array_map(function (CollectionData $collection): NavEntry {
			$item = $collection->labelSingular !== '' ? $collection->labelSingular : $collection->name;
			// A schema without an icon of its name falls back to the collections icon.
			$schema = (string)preg_replace('/[^a-z0-9-]/', '', strtolower($collection->schema));

			return new NavEntry(
				self::NEW_OBJECT . $collection->id,
				$this->translator->trans('dashboard.new_object', ['{item}' => $item]),
				"collections/{$collection->id}/add",
				'collection:create',
				null,
				"var(--icon-{$schema}, var(--icon-collections))",
				$collection->name,
			);
		}, $collections);
	}

	/**
	 * List options for the setting: every action, whether or not the current
	 * user can use it — an admin chooses them for the site. A collection's
	 * action names its id too, as two collections can share a label.
	 *
	 * @return list<array{value: string, label: string}>
	 */
	public function options(): array
	{
		$options = array_map(fn (NavEntry $entry): array => ['value' => $entry->id, 'label' => $entry->label], $this->items());
		foreach ($this->collectionItems() as $entry) {
			$options[] = ['value' => $entry->id, 'label' => $entry->label . ' (' . substr($entry->id, strlen(self::NEW_OBJECT)) . ')'];
		}

		return $options;
	}

	/**
	 * The actions to show the current user, in the setting's order. Ids that
	 * are not in the catalog (an action since removed) are skipped.
	 *
	 * @return list<NavEntry>
	 */
	public function visible(): array
	{
		$chosen = $this->chosen();
		$byId   = [];
		foreach ($this->items() as $entry) {
			$byId[$entry->id] = $entry;
		}
		// Only read the collections when one of their actions is chosen.
		$wantsCollections = array_filter($chosen, fn (string $id): bool => str_starts_with($id, self::NEW_OBJECT)) !== [];
		foreach ($wantsCollections ? $this->collectionItems() : [] as $entry) {
			$byId[$entry->id] = $entry;
		}

		$actions = [];
		foreach ($chosen as $id) {
			if (isset($byId[$id]) && $this->allows($byId[$id])) {
				$actions[] = $byId[$id];
			}
		}

		return $actions;
	}

	/**
	 * Quick Navigation entries for every "New …" action the current user may
	 * use: the catalog's (New Collection, New Schema, …) under a Create
	 * section, and each collection's under the collection's name, which tells
	 * apart two collections whose items share a label.
	 *
	 * @return list<array{label: string, section: string, path: string, keywords: string}>
	 */
	public function quickNavItems(): array
	{
		$create = $this->translator->trans('dashboard.create');
		$items  = [];
		foreach ($this->items() as $entry) {
			if (str_starts_with($entry->id, 'new-') && $this->allows($entry)) {
				$items[] = ['label' => $entry->label, 'section' => $create, 'path' => $entry->url, 'keywords' => 'new add create'];
			}
		}
		foreach ($this->collectionItems() as $entry) {
			if ($this->allows($entry)) {
				$items[] = ['label' => $entry->label, 'section' => $entry->keywords, 'path' => $entry->url, 'keywords' => 'new add create ' . substr($entry->id, strlen(self::NEW_OBJECT))];
			}
		}

		return $items;
	}

	private function allows(NavEntry $entry): bool
	{
		if (!str_starts_with($entry->id, self::NEW_OBJECT)) {
			return $this->nav->allows($entry);
		}

		$collection = substr($entry->id, strlen(self::NEW_OBJECT));

		return $this->collectionEditions->isAccessible($collection) && $this->auth->canAccessCollection($collection, 'create');
	}

	/** @return list<string> */
	private function chosen(): array
	{
		$raw = $this->config->dashboard[self::SETTING] ?? null;
		if (!is_array($raw)) {
			return self::DEFAULTS;
		}

		return array_values(array_unique(array_filter($raw, is_string(...))));
	}
}
