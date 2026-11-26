<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads;

use Psr\Container\ContainerInterface;
use TotalCMS\Bundled\S3Uploads\Access\S3Access;
use TotalCMS\Bundled\S3Uploads\Action\ActionResponder;
use TotalCMS\Bundled\S3Uploads\Action\CommitAction;
use TotalCMS\Bundled\S3Uploads\Action\DownloadRedirectAction;
use TotalCMS\Bundled\S3Uploads\Action\OpenAction;
use TotalCMS\Bundled\S3Uploads\Action\PresignAction;
use TotalCMS\Bundled\S3Uploads\Action\StatsAction;
use TotalCMS\Bundled\S3Uploads\Action\TestConnectionAction;
use TotalCMS\Bundled\S3Uploads\Command\PruneCommand;
use TotalCMS\Bundled\S3Uploads\Command\StatsCommand;
use TotalCMS\Bundled\S3Uploads\Field\S3DepotField;
use TotalCMS\Bundled\S3Uploads\Field\S3Field;
use TotalCMS\Bundled\S3Uploads\Field\S3FileField;
use TotalCMS\Bundled\S3Uploads\Lifecycle\KeyCleanupListener;
use TotalCMS\Bundled\S3Uploads\Lifecycle\PruneService;
use TotalCMS\Bundled\S3Uploads\S3\S3Client;
use TotalCMS\Bundled\S3Uploads\S3\S3Settings;
use TotalCMS\Bundled\S3Uploads\Serving\DownloadCounter;
use TotalCMS\Bundled\S3Uploads\Serving\PointerLocator;
use TotalCMS\Bundled\S3Uploads\Serving\ProductDownloadSource;
use TotalCMS\Bundled\S3Uploads\Serving\UrlResolver;
use TotalCMS\Bundled\S3Uploads\Storage\KeyBuilder;
use TotalCMS\Bundled\S3Uploads\Storage\S3Fields;
use TotalCMS\Bundled\S3Uploads\Twig\S3TwigAdapter;
use TotalCMS\Bundled\S3Uploads\Upload\UploadService;
use TotalCMS\Domain\Auth\Data\Decision;
use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\LoginUrl;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Automation\Data\AutomationContext;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Security\SignedToken;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\FileLock;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Twig\Extension\LazyTwigGlobal;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;

require_once __DIR__ . '/S3/S3Settings.php';
require_once __DIR__ . '/S3/SigV4Signer.php';
require_once __DIR__ . '/S3/ListedObject.php';
require_once __DIR__ . '/S3/S3Exception.php';
require_once __DIR__ . '/S3/S3Client.php';
require_once __DIR__ . '/Access/S3Access.php';
require_once __DIR__ . '/Storage/Pointer.php';
require_once __DIR__ . '/Storage/KeyBuilder.php';
require_once __DIR__ . '/Storage/S3Fields.php';
require_once __DIR__ . '/Upload/UploadRules.php';
require_once __DIR__ . '/Upload/UploadError.php';
require_once __DIR__ . '/Upload/UploadService.php';
require_once __DIR__ . '/Serving/DownloadCounter.php';
require_once __DIR__ . '/Serving/UrlResolver.php';
require_once __DIR__ . '/Serving/PointerLocator.php';
require_once __DIR__ . '/Serving/ProductDownloadSource.php';
require_once __DIR__ . '/Lifecycle/KeyCleanupListener.php';
require_once __DIR__ . '/Lifecycle/PruneReport.php';
require_once __DIR__ . '/Lifecycle/PruneService.php';
require_once __DIR__ . '/Command/PruneCommand.php';
require_once __DIR__ . '/Command/StatsCommand.php';
require_once __DIR__ . '/Twig/S3TwigAdapter.php';
require_once __DIR__ . '/Field/S3Field.php';
require_once __DIR__ . '/Field/S3FileField.php';
require_once __DIR__ . '/Field/S3DepotField.php';
require_once __DIR__ . '/Action/ActionResponder.php';
require_once __DIR__ . '/Action/PresignAction.php';
require_once __DIR__ . '/Action/CommitAction.php';
require_once __DIR__ . '/Action/DownloadRedirectAction.php';
require_once __DIR__ . '/Action/OpenAction.php';
require_once __DIR__ . '/Action/StatsAction.php';
require_once __DIR__ . '/Setup/BucketSnippets.php';
require_once __DIR__ . '/Action/TestConnectionAction.php';

/**
 * Files in the operator's own S3-compatible bucket. The browser uploads
 * straight to the bucket and Total CMS stores a pointer; the bytes never pass
 * through PHP. This file is wiring only.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$settings = S3Settings::fromArray($context->settings());

		$context->addContainerDefinition(S3Settings::class, fn (): S3Settings => $settings);
		$context->addContainerDefinition(
			S3Client::class,
			fn (ContainerInterface $c): S3Client => new S3Client($settings, $c->get(HttpClientInterface::class)),
		);
		$context->addContainerDefinition(KeyBuilder::class, fn (): KeyBuilder => new KeyBuilder($settings->prefix));
		$context->addContainerDefinition(
			S3Fields::class,
			fn (ContainerInterface $c): S3Fields => new S3Fields($c->get(SchemaFetcher::class)),
		);
		$context->addContainerDefinition(
			S3Access::class,
			fn (ContainerInterface $c): S3Access => new S3Access(
				fn (): bool => $c->get(Config::class)->authEnabled(),
				$c->get(AccessManager::class),
				$c->get(AccessControlService::class),
				// In a collection whose members own their objects, only the owner writes.
				fn (string $collection, string $id): bool => $c->get(AccessGuard::class)
					->ownerDecision($c->get(ActorContext::class)->current(), $collection, Operation::Update, $id) === Decision::Allow,
				// The same rule core's /download and /stream apply to a protected file.
				fn (string $userId, array $groups, string $userCollection): bool => $c->get(UserValidationService::class)->validateFileAccess($userId, array_values(array_map(strval(...), $groups)), $userCollection),
			),
		);
		$context->addContainerDefinition(
			UploadService::class,
			fn (ContainerInterface $c): UploadService => new UploadService(
				$settings,
				$c->get(S3Client::class),
				$c->get(KeyBuilder::class),
				$c->get(S3Fields::class),
				self::licensed($c),
				$c->get(SignedToken::class),
				$c->get(ObjectFetcher::class),
				$c->get(ObjectPatcher::class),
				$c->get(S3Access::class),
				$c->get(FileLock::class),
				time(...),
			),
		);
		$context->addContainerDefinition(
			DownloadCounter::class,
			fn (ContainerInterface $c): DownloadCounter => new DownloadCounter($c->get(AtomicJsonStore::class), $c->get(StorageAdapterInterface::class)),
		);
		$context->addContainerDefinition(
			UrlResolver::class,
			fn (ContainerInterface $c): UrlResolver => new UrlResolver($settings, $c->get(S3Client::class), (string)$c->get(Config::class)->api),
		);
		$context->addContainerDefinition(
			PointerLocator::class,
			fn (ContainerInterface $c): PointerLocator => new PointerLocator($settings, $c->get(ObjectFetcher::class), $c->get(S3Fields::class), $c->get(KeyBuilder::class)),
		);
		$context->addContainerDefinition(
			KeyCleanupListener::class,
			fn (ContainerInterface $c): KeyCleanupListener => new KeyCleanupListener(
				$settings,
				$c->get(S3Fields::class),
				$c->get(KeyBuilder::class),
				$c->get(S3Client::class),
				$c->get(DownloadCounter::class),
				$c->get(ObjectPatcher::class),
				$context->logger(),
			),
		);
		$context->addContainerDefinition(
			S3TwigAdapter::class,
			fn (ContainerInterface $c): S3TwigAdapter => new S3TwigAdapter(
				$c->get(UrlResolver::class),
				$c->get(DownloadCounter::class),
				$settings,
				$c->get(KeyBuilder::class),
				$c->get(Config::class)->env !== 'prod',
				$context->logger(),
				function (string $collection, string $id) use ($c): ?array {
					try {
						return $c->get(ObjectFetcher::class)->fetchObject($collection, $id)->toArray();
					} catch (\Throwable) {
						return null;
					}
				},
			),
		);

		$this->registerActions($context, $settings);

		S3Field::configure($settings->isConfigured() ? null : 'Uploads are off: S3 Uploads is not configured. A super admin can set it up in Extensions → S3 Uploads.');
		$context->addFieldType('s3file', S3FileField::class, 'json');
		$context->addFieldType('s3depot', S3DepotField::class, 'json');
		// A store product's Download from S3 field: the store asks here for a
		// product's download, and sends the buyer to a signed link to it.
		$context->addDownloadSource(new ProductDownloadSource(
			fn (): PointerLocator => $context->get(PointerLocator::class),
			fn (): UrlResolver => $context->get(UrlResolver::class),
			fn (): DownloadCounter => $context->get(DownloadCounter::class),
		));
		$context->addAdminAsset('css', 's3-field.css');
		$context->addAdminAsset('js', 's3-field.js');

		// Resolved on first use: services cannot be built while extensions register.
		$context->addTwigGlobal('s3', new LazyTwigGlobal(fn (): S3TwigAdapter => $context->get(S3TwigAdapter::class)));

		$context->addContainerDefinition(
			PruneService::class,
			fn (ContainerInterface $c): PruneService => new PruneService(
				$settings,
				$c->get(S3Client::class),
				$c->get(S3Fields::class),
				$c->get(CollectionLister::class),
				$c->get(IndexRepository::class),
				$c->get(ObjectFetcher::class),
				time(...),
			),
		);
		$context->addCommand(new PruneCommand(fn (): PruneService => $context->get(PruneService::class)));
		$context->addCommand(new StatsCommand(fn (): DownloadCounter => $context->get(DownloadCounter::class)));

		// Registered whether or not a bucket is configured. A capability an
		// enabled extension gains later starts switched OFF, and every site
		// enables the extension before it adds the bucket: registering these
		// only once configured would leave cleanup off for good. Unconfigured,
		// they do nothing.
		$listener = fn (): KeyCleanupListener => $context->get(KeyCleanupListener::class);
		$context->addEventListener(CoreEvent::OBJECT_UPDATED, fn (array $payload) => $listener()->onUpdated($payload));
		$context->addEventListener(CoreEvent::OBJECT_DELETED, fn (array $payload) => $listener()->onDeleted($payload));
		$context->addEventListener(CoreEvent::OBJECT_CREATED, fn (array $payload) => $listener()->onCreated($payload));

		// Nightly. Only a live site deletes: a staging or local copy that shares
		// the bucket by mistake reports instead of removing files. With no
		// prefix the run refuses, so nothing is deleted then either.
		$context->addAutomation(
			'prune',
			'Remove unused files from the S3 bucket',
			[['type' => 'schedule', 'cron' => '30 3 * * *']],
			fn (AutomationContext $automation): array => $settings->isConfigured()
				? $context->get(PruneService::class)->run($context->get(Config::class)->env === 'prod')->toArray()
				: ['skipped' => 'S3 Uploads is not configured.'],
		);
	}

	private function registerActions(ExtensionContext $context, S3Settings $settings): void
	{
		$context->addContainerDefinition(
			ActionResponder::class,
			fn (ContainerInterface $c): ActionResponder => new ActionResponder($c->get(JsonRenderer::class), $settings),
		);
		$context->addContainerDefinition(
			PresignAction::class,
			fn (ContainerInterface $c): PresignAction => new PresignAction($c->get(UploadService::class), $c->get(ActionResponder::class)),
		);
		$context->addContainerDefinition(
			CommitAction::class,
			fn (ContainerInterface $c): CommitAction => new CommitAction($c->get(UploadService::class), $c->get(ActionResponder::class)),
		);
		$context->addContainerDefinition(
			OpenAction::class,
			fn (ContainerInterface $c): OpenAction => new OpenAction(
				$c->get(PointerLocator::class),
				$c->get(S3Client::class),
				$c->get(S3Access::class),
			),
		);
		$context->addContainerDefinition(
			StatsAction::class,
			fn (ContainerInterface $c): StatsAction => new StatsAction(
				$c->get(DownloadCounter::class),
				$c->get(S3Access::class),
				$c->get(JsonRenderer::class),
			),
		);
		$context->addContainerDefinition(
			TestConnectionAction::class,
			fn (ContainerInterface $c): TestConnectionAction => new TestConnectionAction($c->get(S3Client::class), $settings, $c->get(TwigRenderer::class)),
		);
		$context->addContainerDefinition(
			DownloadRedirectAction::class,
			fn (ContainerInterface $c): DownloadRedirectAction => new DownloadRedirectAction(
				$c->get(PointerLocator::class),
				$c->get(UrlResolver::class),
				$c->get(DownloadCounter::class),
				$c->get(CollectionFetcher::class),
				$c->get(S3Access::class),
				fn (string $collection, string $returnTo): string => $c->get(LoginUrl::class)->forCollection($collection, $returnTo),
				$c->get(EventDispatcher::class),
				$c->get(LoggerFactory::class)->channelLogger(LogChannel::FileAccess),
			),
		);

		// Editors upload, not just super admins. Access Groups can still
		// withhold the extension, and each action checks the collection itself.
		$context->addAdminRoutes(function ($group): void {
			$group->post('/presign', PresignAction::class, permission: 'any');
			$group->post('/commit', CommitAction::class, permission: 'any');
			$group->get('/open', OpenAction::class, permission: 'any');
			$group->get('/stats', StatsAction::class, permission: 'any');
			$group->get('/test', TestConnectionAction::class);
			$group->post('/test', TestConnectionAction::class);
		});

		// The link templates print. Public: it enforces access itself, per property.
		$context->addPublicRoutes(function ($group): void {
			// Named as core names its own file routes: /stream shows the file, /download saves it.
			$group->get('/{mode:stream|download}/{collection}/{id}/{property}', DownloadRedirectAction::class);
			$group->get('/{mode:stream|download}/{collection}/{id}/{property}/{file}', DownloadRedirectAction::class);
		});
	}

	public function boot(ExtensionContext $context): void
	{
	}

	/**
	 * Whether this site may upload: Pro or above. Serving, counting and cleanup
	 * never ask, so a lapsed license stops new uploads without breaking links.
	 *
	 * @return \Closure(): bool
	 */
	private static function licensed(ContainerInterface $container): \Closure
	{
		return static function () use ($container): bool {
			try {
				return $container->get(EditionFeatureService::class)->getEdition()->level() >= Edition::PRO->level();
			} catch (\Throwable) {
				return false;
			}
		};
	}
}
