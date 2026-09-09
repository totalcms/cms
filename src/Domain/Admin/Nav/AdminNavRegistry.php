<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Nav;

use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\EditionTwigAdapter;
use TotalCMS\Support\Config;

/**
 * The one list behind the admin sidebar rail.
 *
 * The dashboard shell, the quick-nav index and the "More menu" setting all
 * read from here, so a page added to the rail shows up in all three without
 * anyone hand-editing a template. Each entry carries the same auth and
 * edition rule the template used to spell out inline; {@see visible()}
 * applies them for the current request, and {@see menu()} then splits what
 * is left by the `dashboard.moreMenu` setting — ids the operator has moved
 * into the three-dots popover at the bottom of the rail.
 */
readonly class AdminNavRegistry
{
	public const SETTING = 'moreMenu';

	public function __construct(
		private AuthTwigAdapter $auth,
		private EditionTwigAdapter $edition,
		private TranslationService $translator,
		private Config $config,
		// Required on purpose: PHP-DI skips optional constructor parameters,
		// so an optional manager would stay null and extension items would
		// silently never reach the rail.
		private ExtensionManager $extensions,
	) {
	}

	/**
	 * Every entry in rail order, before any permission check: core pages,
	 * then extension items (which sit before Utils, as they always have),
	 * then the trailing core pages.
	 *
	 * @return list<NavEntry>
	 */
	public function items(): array
	{
		$t = fn (string $key): string => $this->translator->trans($key);

		$head = [
			new NavEntry('collections', $t('nav.collections'), 'collections', 'collections:read', null, 'var(--icon-collections)'),
			new NavEntry('schemas', $t('nav.schemas'), 'schemas', 'schemas:read', null, 'var(--icon-schema)'),
			new NavEntry('dataviews', $t('nav.dataviews'), 'dataviews', 'dataviews', 'data_views', 'var(--icon-data-views)'),
			new NavEntry('builder', $t('nav.builder'), 'builder', 'builder', null, 'var(--icon-templates)', 'nav site pages templates'),
			new NavEntry('mailer', $t('nav.mailer'), 'mailer', 'mailer', 'mailer_actions', 'var(--icon-mailer)', 'nav email'),
			new NavEntry('automations', $t('nav.automations'), 'automations', 'admin', 'automations', 'var(--icon-automations)', 'nav schedule cron webhook event jobs'),
			new NavEntry('playground', $t('nav.playground'), 'playground', 'playground', null, 'var(--icon-twig-playground)', 'nav twig test'),
		];
		$tail = [
			new NavEntry('utils', $t('nav.utils'), 'utils', 'utils', null, 'var(--icon-tools)', 'nav utilities tools'),
			new NavEntry('extensions', $t('nav.extensions'), 'extensions', 'admin', null, 'var(--icon-extension)', 'nav plugins'),
			new NavEntry('settings', $t('nav.settings'), 'settings', 'admin', null, 'var(--icon-cog)', 'nav config configuration'),
			new NavEntry('docs', $t('nav.docs'), 'docs', 'docs', null, 'var(--icon-docs)', 'nav documentation help'),
		];
		$extension = array_map(self::fromExtension(...), $this->extensions->getAllAdminNavItems());

		return [...$head, ...$extension, ...$tail];
	}

	/**
	 * An extension's nav item as a rail entry. The id is stable across
	 * requests and unique per extension + url, so the setting can name it.
	 */
	public static function fromExtension(AdminNavItem $item): NavEntry
	{
		$slug = trim($item->url, '/');

		return new NavEntry(
			id         : "ext:{$item->extensionId}:{$slug}",
			label      : $item->label,
			url        : $item->url,
			permission : $item->permission === 'any' ? 'extension' : 'admin',
			icon       : $item->icon,
			keywords   : 'extension plugin',
			extensionId: $item->extensionId,
		);
	}

	/**
	 * The entries the current user may see, in rail order.
	 *
	 * @return list<NavEntry>
	 */
	public function visible(): array
	{
		return array_values(array_filter($this->items(), $this->allowed(...)));
	}

	/**
	 * Ids the operator moved into the More menu (`dashboard.moreMenu`).
	 * Anything that is not a string is ignored rather than trusted.
	 *
	 * @return list<string>
	 */
	public function hidden(): array
	{
		$raw = $this->config->dashboard[self::SETTING] ?? [];
		if (!is_array($raw)) {
			return [];
		}

		return array_values(array_filter($raw, is_string(...)));
	}

	/**
	 * The visible entries split for the template: `rail` stays in the
	 * sidebar, `more` goes behind the three-dots button.
	 *
	 * @return array{rail: list<NavEntry>, more: list<NavEntry>}
	 */
	public function menu(): array
	{
		return self::split($this->visible(), $this->hidden());
	}

	/**
	 * @param list<NavEntry> $entries
	 * @param list<string>   $hidden
	 *
	 * @return array{rail: list<NavEntry>, more: list<NavEntry>}
	 */
	public static function split(array $entries, array $hidden): array
	{
		$rail = [];
		$more = [];
		foreach ($entries as $entry) {
			if (in_array($entry->id, $hidden, true)) {
				$more[] = $entry;
			} else {
				$rail[] = $entry;
			}
		}

		return ['rail' => $rail, 'more' => $more];
	}

	/**
	 * Checklist options for the setting: every entry, whether or not the
	 * current user can see it — an admin configures the rail for the site.
	 *
	 * @return list<array{value: string, label: string}>
	 */
	public function options(): array
	{
		return array_map(fn (NavEntry $entry): array => ['value' => $entry->id, 'label' => $entry->label], $this->items());
	}

	private function allowed(NavEntry $entry): bool
	{
		if ($entry->edition !== null && !$this->edition->can($entry->edition)) {
			return false;
		}

		return match ($entry->permission) {
			'collections:read' => $this->auth->canAccessCollectionsOperation('read'),
			'schemas:read'     => $this->auth->canAccessSchemasOperation('read'),
			'dataviews'        => $this->auth->canAccessDataViews(),
			'builder'          => $this->auth->canAccessBuilder(),
			'mailer'           => $this->auth->canAccessMailer(),
			'playground'       => $this->auth->canAccessPlayground(),
			'utils'            => $this->auth->canAccessUtils(),
			'docs'             => $this->auth->canAccessDocs(),
			'admin'            => $this->auth->isAdmin(),
			'extension'        => $this->auth->canAccessExtension($entry->extensionId),
			default            => false,
		};
	}
}
