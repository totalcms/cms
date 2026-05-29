<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Sync\Data\SyncableCollections;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Domain\Twig\Service\TwigLintService;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\Renderer\TwigRenderer;

readonly class AdminUtilsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private TwigEngine $twigEngine,
		private TwigLintService $twigLintService,
		private ApiKeyFetcher $apiKeyFetcher,
		private AccessGroupLister $accessGroupLister,
		private CollectionLister $collectionLister,
		private CollectionFetcher $collectionFetcher,
		private IndexReader $indexReader,
		private BuilderInstaller $builderInstaller,
		private SchemaLister $schemaLister,
		private RssImporter $rssImporter,
		private EditionFeatureService $editionFeatures,
		private SettingsFetcher $settingsFetcher,
		private TemplateLister $templateLister,
		private UpdateChecker $updateChecker,
		private OAuthClientRepository $oauthClientRepository,
		private OAuthGrantRepository $oauthGrantRepository,
		private OAuthScopeRegistry $oauthScopeRegistry,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		// Handle specific routes by setting expected page based on route name
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		$routeName    = $route?->getName() ?? '';

		if ($routeName === 'admin-utils-access-groups') {
			$page         = 'access-groups';
			$args['page'] = 'access-groups';
		} elseif ($routeName === 'admin-utils-api-keys') {
			$page         = 'api-keys';
			$args['page'] = 'api-keys';
		} else {
			$page = $args['page'] ?? 'index';
		}

		$query   = $request->getQueryParams();
		$action  = $args['action'] ?? $query['action'] ?? '';
		$results = '';

		if ($request->getMethod() === 'POST') {
			$post = (array)$request->getParsedBody();

			if ($page === 'twig-playground' && isset($post['twig'])) {
				try {
					$results = $this->twigEngine->renderString((string)$post['twig']);
				} catch (\Throwable $e) {
					$results = sprintf('<div class="error"><pre><code>%s</code></pre></div>', htmlspecialchars($e->getMessage()));
				}
			}
		}

		// Detect Total CMS 1 data for project-setup page
		$totalcms1DetectionData = null;
		if ($page === 'project-setup' || $page === 'import-totalcms-one') {
			$totalcms1DetectionData = $this->detectTotalCms1Data();

			// Create default collections when requested
			if ($action === 'default-collections') {
				$this->createDefaultCollections();
			}
		}

		// Fetch API keys for api-keys page
		$apiKeys = null;
		if ($page === 'api-keys' && $action !== 'new') {
			$apiKeys = $this->apiKeyFetcher->getAllKeys();
		}

		// Fetch OAuth clients for oauth-clients page
		$oauthClients     = null;
		$oauthClientsForm = null;
		if ($page === 'oauth-clients' && $action !== 'new') {
			$oauthClients = $this->prepareClientsForView();
		}
		if ($page === 'oauth-clients' && $action === 'new') {
			$oauthClientsForm = [
				'scopes' => $this->oauthScopeRegistry->all(),
			];
		}

		// Fetch OAuth grants for oauth-grants page
		$oauthGrants = null;
		if ($page === 'oauth-grants') {
			$oauthGrants = $this->prepareGrantsForView();
		}

		// Fetch access groups data for access-groups page
		$accessGroupsData = null;
		if ($page === 'access-groups') {
			$accessGroupsData = $this->createAccessGroupData($action);
		}

		// Check edition for import pages (RSS, WordPress)
		if (in_array($page, ['import-rss', 'import-wordpress'], true) && !$this->editionFeatures->can(EditionFeature::RSS_IMPORT)) {
			$feature         = EditionFeature::RSS_IMPORT;
			$requiredEdition = $feature->requiredEdition();

			return $this->twigRenderer->template($response, 'access-denied.twig', [
				'message'  => sprintf(
					'The "%s" feature requires the %s edition or higher.',
					$feature->label(),
					ucfirst($requiredEdition->value)
				),
				'details'  => null,
				'referrer' => $request->getHeaderLine('Referer') ?: null,
			]);
		}

		// Analyze RSS feed for import-rss page
		$rssAnalysis = null;
		$rssError    = null;
		if ($page === 'import-rss' && $request->getMethod() === 'POST') {
			$post    = (array)$request->getParsedBody();
			$feedUrl = isset($post['url']) ? trim((string)$post['url']) : '';
			if ($feedUrl !== '') {
				try {
					$rssAnalysis = $this->rssImporter->analyze($feedUrl);
				} catch (\Throwable $e) {
					$rssError = $e->getMessage();
				}
			}
		}

		// Update utility data
		$updateInfo = null;
		if ($page === 'update') {
			$forceCheck = ($query['check'] ?? '') === '1';
			try {
				$updateInfo = $this->updateChecker->checkForUpdate($forceCheck);
			} catch (\Throwable) {
				// Silently fail — update check is not critical
			}
		}

		// Sync utility data
		$syncData = null;
		if ($page === 'sync') {
			$syncData = [
				'settings'    => $this->settingsFetcher->loadSection('sync'),
				'schemas'     => $this->schemaLister->listCustomSchemas(),
				'templates'   => $this->templateLister->listBuilderTemplates(null, true),
				'collections' => $this->resolveSyncableCollections(),
			];
		}

		// Handle twig-debugger page
		$lintResults = null;
		if ($page === 'twig-debugger') {
			$filepath = null;

			// Check POST first, then query params
			if ($request->getMethod() === 'POST') {
				$post     = (array)$request->getParsedBody();
				$filepath = isset($post['filepath']) && $post['filepath'] !== '' ? (string)$post['filepath'] : null;
			} else {
				$query    = $request->getQueryParams();
				$filepath = isset($query['filepath']) && $query['filepath'] !== '' ? (string)$query['filepath'] : null;
			}

			if ($filepath !== null) {
				$lintResults = $this->lintTwigFile($filepath);
			}
		}

		return $this->twigRenderer->template($response, 'admin/utils.twig', [
			'page'   => $page,
			'action' => $action,
			'url'    => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'utils',
			],
			'results'                => $results,
			'totalcms1DetectionData' => $totalcms1DetectionData,
			'apiKeys'                => $apiKeys,
			'accessGroupsData'       => $accessGroupsData,
			'oauthClients'           => $oauthClients,
			'oauthClientsForm'       => $oauthClientsForm,
			'oauthGrants'            => $oauthGrants,
			'lintResults'            => $lintResults,
			'rssAnalysis'            => $rssAnalysis,
			'rssError'               => $rssError,
			'rssCollections'         => $rssAnalysis !== null ? $this->collectionLister->listAllCollections() : null,
			'updateInfo'             => $updateInfo,
			'composerInstall'        => \TotalCMS\Support\PathResolver::isComposerInstall(),
			'syncData'               => $syncData,
			'postData'               => $request->getMethod() === 'POST' ? (array)$request->getParsedBody() : [],
		]);
	}

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @return array<string,string>|null
	 */
	private function detectTotalCms1Data(): ?array
	{
		// Check production location first
		$documentRoot   = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$productionPath = $documentRoot . '/cms-data';

		if (is_dir($productionPath)) {
			return [
				'path'   => $productionPath,
				'source' => 'production',
			];
		}

		// Check test data location
		$testPath = __DIR__ . '/../../../tests/test-data/cms-data';
		$testPath = realpath($testPath);

		if ($testPath && is_dir($testPath)) {
			return [
				'path'   => $testPath,
				'source' => 'test',
			];
		}

		return null;
	}

	/** @return array<string,mixed> */
	private function createAccessGroupData(string $action): array
	{
		// Ensure the default group exists for backwards compatibility
		$this->accessGroupLister->ensureDefaultGroupExists();

		$isEdit = $action !== 'new' && $action !== '';

		return [
			'groups'      => $this->accessGroupLister->listAll(),
			'collections' => $this->collectionLister->listAllCollections(),
			'schemas'     => $this->schemaLister->listAllSchemas(),
			'group'       => $isEdit ? $this->accessGroupLister->findById($action) : '',
			'isEdit'      => $isEdit,
		];
	}

	/**
	 * Build the OAuth clients view data: two bucketed lists (static / dynamic)
	 * with an active-grant count per client.
	 *
	 * @return array<string,mixed>
	 */
	private function prepareClientsForView(): array
	{
		$allClients = $this->oauthClientRepository->all();

		$static  = [];
		$dynamic = [];

		foreach ($allClients as $client) {
			$grantCount = count($this->oauthGrantRepository->findByClientId($client->id));
			$row        = ['client' => $client, 'grantCount' => $grantCount];

			if ($client->isDynamic) {
				$dynamic[] = $row;
			} else {
				$static[] = $row;
			}
		}

		return [
			'static'  => $static,
			'dynamic' => $dynamic,
		];
	}

	/**
	 * Build the OAuth grants view data: every grant joined with its client
	 * name and expiry metadata, sorted most-recent first.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function prepareGrantsForView(): array
	{
		$now    = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$grants = $this->oauthGrantRepository->all();

		$rows = [];

		foreach ($grants as $grant) {
			$client     = $this->oauthClientRepository->find($grant->clientId);
			$clientName = $client !== null ? $client->name : $grant->clientId;

			$expiresAt       = null;
			$isExpired       = false;
			$daysUntilExpiry = null;

			if ($grant->expiresAt !== '') {
				try {
					$expiry          = new \DateTimeImmutable($grant->expiresAt, new \DateTimeZone('UTC'));
					$expiresAt       = $expiry;
					$isExpired       = $expiry < $now;
					$diff            = $now->diff($expiry);
					$daysUntilExpiry = $isExpired ? -(int)$diff->days : (int)$diff->days;
				} catch (\Exception) {
					// leave null
				}
			}

			$rows[] = [
				'grant'           => $grant,
				'clientName'      => $clientName,
				'clientId'        => $grant->clientId,
				'isExpired'       => $isExpired,
				'expiresAt'       => $expiresAt,
				'daysUntilExpiry' => $daysUntilExpiry,
			];
		}

		// Sort by issuedAt descending (most recent first)
		usort($rows, static function (array $a, array $b): int {
			/** @var \TotalCMS\Domain\OAuth\Data\OAuthGrantData $ga */
			$ga = $a['grant'];
			/** @var \TotalCMS\Domain\OAuth\Data\OAuthGrantData $gb */
			$gb = $b['grant'];

			return strcmp($gb->issuedAt, $ga->issuedAt);
		});

		return $rows;
	}

	/**
	 * Create all default/reserved collections.
	 * Skips blog-legacy as it's deprecated.
	 */
	private function createDefaultCollections(): void
	{
		foreach (SchemaData::RESERVED_SCHEMAS as $schemaId) {
			// Skip schemas that don't map 1:1 to a collection
			if ($schemaId === 'blog-legacy' || $schemaId === 'builder-page') {
				continue;
			}
			$this->collectionFetcher->fetchOrCreateReserved($schemaId);
		}

		// Builder pages collection uses a different collection ID than schema ID
		$this->builderInstaller->ensurePagesCollection();
	}

	/**
	 * Lint a Twig file for syntax errors.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @return array<string,mixed>
	 */
	private function lintTwigFile(string $relativePath): array
	{
		// Construct full path from document root
		$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

		// Clean the path - remove leading slashes for consistency
		$relativePath = ltrim($relativePath, '/');

		// Build absolute path
		$absolutePath = $documentRoot . '/' . $relativePath;

		// Security check: ensure the path is within document root
		$realPath = realpath($absolutePath);

		if ($realPath === false) {
			return [
				'success' => false,
				'error'   => [
					'message' => "File not found: {$relativePath}",
					'line'    => 0,
					'context' => '',
				],
				'file'    => $relativePath,
			];
		}

		if (!str_starts_with($realPath, (string)$documentRoot)) {
			return [
				'success' => false,
				'error'   => [
					'message' => 'Access denied: path outside document root',
					'line'    => 0,
					'context' => '',
				],
				'file'    => $relativePath,
			];
		}

		return $this->twigLintService->lintFile($realPath)->toArray();
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
			if ($collection === null) {
				continue;
			}

			try {
				$index = $this->indexReader->fetchIndex($id);
			} catch (\Throwable) {
				$index = null;
			}

			$objects = [];
			if ($index !== null) {
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
	 * @param array<string,mixed> $entry
	 */
	private function labelForIndexEntry(array $entry, string $fallback): string
	{
		foreach (['title', 'name', 'subject', 'route', 'label'] as $field) {
			$value = $entry[$field] ?? null;
			if (is_string($value) && trim($value) !== '') {
				return $value;
			}
		}

		return $fallback;
	}
}
