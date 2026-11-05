<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\ClientHelp;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Docs\Service\DocsMarkdownRenderer;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\Adapter\CollectionTwigAdapter;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Domain\Video\Service\VideoUrlResolver;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

// The extension loader only require_once's the entrypoint, so sibling files are
// loaded here.
require_once __DIR__ . '/HelpLibrary.php';
require_once __DIR__ . '/HelpAction.php';

/**
 * Help pages a freelancer writes for their client, shown only in the admin.
 * The posts live in a private collection that this extension keeps in place.
 *
 * Help pages read that collection on the server, so a client needs the
 * extension's access-group grant but no access to the collection itself.
 */
class Extension implements ExtensionInterface
{
	public const COLLECTION = 'client-help';

	private const DEFAULT_LABEL = 'Help';

	public function register(ExtensionContext $context): void
	{
		$context->addAdminNavItem(self::navItem((string)$context->setting('navLabel', self::DEFAULT_LABEL)));

		$logger = $context->logger();

		$context->addContainerDefinition(
			HelpLibrary::class,
			static fn (ContainerInterface $c): HelpLibrary => new HelpLibrary(
				$c->get(CollectionTwigAdapter::class),
				$c->get(SchemaFetcher::class),
				$c->get(DocsMarkdownRenderer::class),
				$c->get(VideoUrlResolver::class),
			),
		);

		$context->addContainerDefinition(
			HelpAction::class,
			static fn (ContainerInterface $c): HelpAction => new HelpAction(
				$c->get(HelpLibrary::class),
				$c->get(TwigRenderer::class),
				$c->get(TwigEngine::class),
				$c->get(ExtensionSettingsManager::class),
				$c->get(AccessManager::class),
				$c->get(AccessControlService::class),
				$c->get(Config::class),
				$logger,
			),
		);

		$context->addAdminRoutes(static function ($group): void {
			$group->get('/home', HelpAction::class, permission: 'any');
			$group->get('/post/{id}', HelpAction::class, permission: 'any');
		});
	}

	public function boot(ExtensionContext $context): void
	{
		self::ensureCollection(
			$context->get(CollectionFetcher::class),
			$context->get(CollectionSaver::class),
			$context->logger(),
		);
	}

	/** The admin route needs a path after the extension name, hence `/home`. */
	public static function navItem(string $label): AdminNavItem
	{
		return new AdminNavItem(
			label: trim($label) !== '' ? trim($label) : self::DEFAULT_LABEL,
			icon: 'var(--icon-help)',
			url: '/ext/totalcms/client-help/home',
			permission: 'any',
		);
	}

	/**
	 * Runs on every boot rather than on `extension.enabled`, so a deleted
	 * collection comes back. Two first requests can race here, and the loser's
	 * exception must not reach the boot error handler, which would flag the
	 * whole extension as failed.
	 *
	 * The feed is off explicitly: EnableCollectionFeedsMigration turns feeds on
	 * for anything inheriting `blog` unless `enabled` is set.
	 */
	public static function ensureCollection(CollectionFetcher $fetcher, CollectionSaver $saver, LoggerInterface $logger): void
	{
		if ($fetcher->collectionExists(self::COLLECTION)) {
			return;
		}

		try {
			$saver->saveCollection([
				'id'     => self::COLLECTION,
				'name'   => 'Client Help',
				'schema' => 'blogdown',
				'feed'   => ['enabled' => false],
			]);
		} catch (\Throwable $e) {
			$logger->warning('Client Help could not create its collection: ' . $e->getMessage());
		}
	}
}
