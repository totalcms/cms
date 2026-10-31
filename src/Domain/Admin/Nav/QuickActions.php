<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Nav;

use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;

/**
 * The dashboard's Quick Actions: a fixed catalog that the
 * `dashboard.quickActions` setting picks from and orders. Each action is a
 * {@see NavEntry}, so it is checked with the sidebar's own auth and edition
 * rules ({@see AdminNavRegistry::allows()}).
 */
final readonly class QuickActions
{
	public const SETTING = 'quickActions';

	/** What the dashboard shows until the setting is saved. */
	public const DEFAULTS = ['new-collection', 'new-schema', 'new-template', 'logs', 'jobqueue', 'cache-manager'];

	public function __construct(
		private AdminNavRegistry $nav,
		private TranslationService $translator,
		private Config $config,
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
	 * List options for the setting: every action, whether or not the current
	 * user can use it — an admin chooses them for the site.
	 *
	 * @return list<array{value: string, label: string}>
	 */
	public function options(): array
	{
		return array_map(fn (NavEntry $entry): array => ['value' => $entry->id, 'label' => $entry->label], $this->items());
	}

	/**
	 * The actions to show the current user, in the setting's order. Ids that
	 * are not in the catalog (an action since removed) are skipped.
	 *
	 * @return list<NavEntry>
	 */
	public function visible(): array
	{
		$byId = [];
		foreach ($this->items() as $entry) {
			$byId[$entry->id] = $entry;
		}

		$actions = [];
		foreach ($this->chosen() as $id) {
			if (isset($byId[$id]) && $this->nav->allows($byId[$id])) {
				$actions[] = $byId[$id];
			}
		}

		return $actions;
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
