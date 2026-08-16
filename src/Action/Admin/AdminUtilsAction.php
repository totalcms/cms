<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Domain\Playground\Data\PlaygroundData;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Sync\Data\SyncableCollections;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Domain\Twig\Service\TwigLintService;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\Domain\Visualizer\Service\VisualizerService;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

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
		private \TotalCMS\Domain\Extension\Service\ExtensionManager $extensionManager,
		private VisualizerService $visualizerService,
		private SessionInterface $session,
		private \TotalCMS\Domain\Builder\Service\BuilderTemplatePaths $builderTemplatePaths,
		private AccessControlService $accessControlService,
		private McpSchemaResolver $mcpSchemaResolver,
		private Config $config,
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

			$syncData = [
				'settings'            => $this->settingsFetcher->loadSection('sync'),
				'schemas'             => $this->schemaLister->listCustomSchemas(),
				'templates'           => $templatesGitManaged ? [] : $this->templateLister->listBuilderTemplates(null, true),
				'templatesGitManaged' => $templatesGitManaged,
				'collections'         => $this->resolveSyncableCollections(),
				'collectionMeta'      => $collectionMeta,
			];
		}

		// JumpStart utility data
		$jumpstartData = null;
		if ($page === 'jumpstart') {
			$jumpstartData = [
				// Only custom schema *definitions* are exported (exportCustomSchemas),
				// so the picker lists custom schemas only — reserved/extension schemas
				// would be non-functional choices. Collections list all (reserved
				// collections' objects DO export).
				'schemas'     => $this->schemaLister->listCustomSchemas(),
				'collections' => $this->collectionLister->listAllCollections(),
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

		$currentUserId = (string)($this->session->get(SessionKeys::AUTH_USER) ?? '');

		// Collection Visualizer — relationship graph rendered as a Mermaid ERD.
		$visualizerData = null;
		if ($page === 'collection-visualizer') {
			$visualizerData = $this->visualizerService->collectionGraph(
				$this->collectionLister->listAllCollections(),
				$query,
				$currentUserId !== '' ? $currentUserId : null,
			);
		}

		// Object Visualizer — one record's actual inbound/outbound references.
		$objectVisualizerData = null;
		if ($page === 'object-visualizer') {
			$objectVisualizerData = $this->visualizerService->objectGraph(
				$this->collectionLister->listAllCollections(),
				$query,
				$currentUserId !== '' ? $currentUserId : null,
			);
		}

		// Permission Matrix — what each access group can do, as a matrix.
		$permissionMatrixData = null;
		if ($page === 'permission-matrix') {
			$group                     = isset($query['group']) ? trim((string)$query['group']) : '';
			$permissionMatrixData      = [
				'matrix' => $this->visualizerService->accessGroupMatrix(),
				'group'  => $group,
			];
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
			'results'                   => $results,
			'totalcms1DetectionData'    => $totalcms1DetectionData,
			'apiKeys'                   => $apiKeys,
			'accessGroupsData'          => $accessGroupsData,
			'oauthClients'              => $oauthClients,
			'oauthClientsForm'          => $oauthClientsForm,
			'oauthGrants'               => $oauthGrants,
			'lintResults'               => $lintResults,
			'rssAnalysis'               => $rssAnalysis,
			'rssError'                  => $rssError,
			'rssCollections'            => $rssAnalysis !== null ? $this->collectionLister->listAllCollections() : null,
			'updateInfo'                => $updateInfo,
			'composerInstall'           => \TotalCMS\Support\PathResolver::isComposerInstall(),
			'syncData'                  => $syncData,
			'jumpstartData'             => $jumpstartData,
			'visualizerData'            => $visualizerData,
			'objectVisualizerData'      => $objectVisualizerData,
			'permissionMatrixData'      => $permissionMatrixData,
			'postData'                  => $request->getMethod() === 'POST' ? (array)$request->getParsedBody() : [],
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
			'extensions'  => $this->extensionManager->listExtensionsWithAdminSurface(),
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
		$now     = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

		foreach ($allClients as $client) {
			// The card advertises "active grants" — expired grants (lapsed
			// refresh windows) don't count, they only await oauth:gc.
			$grantCount = count(array_filter(
				$this->oauthGrantRepository->findByClientId($client->id),
				static function (OAuthGrantData $grant) use ($now): bool {
					try {
						return new \DateTimeImmutable($grant->expiresAt, new \DateTimeZone('UTC')) > $now;
					} catch (\Exception) {
						return false;
					}
				},
			));
			$row = ['client' => $client, 'grantCount' => $grantCount];

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
	 * Maximum number of collection ids shown per read/write list on the
	 * OAuth Grants "Effective reach" row before the remainder collapses
	 * into a "+N more" note.
	 */
	private const EFFECTIVE_REACH_BADGE_CAP = 6;

	/**
	 * Build the OAuth grants view data: every grant joined with its client
	 * name, expiry metadata, and effective reach — sorted most-recent first.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function prepareGrantsForView(): array
	{
		$now    = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$grants = $this->oauthGrantRepository->all();

		$authCollection = (string)$this->config->auth['collection'];
		$allCollections = $this->collectionLister->listAllCollections();

		/** @var array<string,array{exists: bool, authority: UserAuthority}> $authorityCache */
		$authorityCache = [];

		$rows = [];

		foreach ($grants as $grant) {
			$client     = $this->oauthClientRepository->find($grant->clientId);
			$clientName = $client instanceof \TotalCMS\Domain\OAuth\Data\OAuthClientData ? $client->name : $grant->clientId;

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
				'effectiveReach'  => $this->effectiveReachForGrant($grant, $authCollection, $allCollections, $authorityCache),
			];
		}

		// Sort by issuedAt descending (most recent first)
		usort($rows, static function (array $a, array $b): int {
			/** @var OAuthGrantData $ga */
			$ga = $a['grant'];
			/** @var OAuthGrantData $gb */
			$gb = $b['grant'];

			return strcmp($gb->issuedAt, $ga->issuedAt);
		});

		return $rows;
	}

	/**
	 * Compute what a grant can actually touch once its user's access groups
	 * apply — the "Effective reach" row on the OAuth Grants admin page. A
	 * raw scope badge like cms:admin can overstate reach: the real ceiling
	 * is consented scopes ∩ access-group permissions ∩ collection mcp.access
	 * exposure — the same rule McpAuth::resolvePersona(),
	 * PersonaContext::canReadCollection(), and ObjectTools::requireExposed()
	 * apply at request time, mirrored here rather than reimplemented.
	 *
	 * $authorityCache is keyed by the composite OAuthUserRef string
	 * ("collection:id") and shared across every grant in the request — most
	 * sites have far fewer distinct users than grants, so a user's authority
	 * is resolved at most once per page render.
	 *
	 * @param  array<CollectionData>                                      $allCollections
	 * @param  array<string,array{exists: bool, authority: UserAuthority}> $authorityCache
	 *
	 * @return array{fullAdmin: bool, userMissing: bool, noAccess: bool, readable: list<string>, readableOverflow: int, writable: list<string>, writableOverflow: int, deleteOnly: list<string>, deleteOnlyOverflow: int}
	 */
	private function effectiveReachForGrant(
		OAuthGrantData $grant,
		string $authCollection,
		array $allCollections,
		array &$authorityCache,
	): array {
		$empty = [
			'fullAdmin'          => false,
			'userMissing'        => false,
			'noAccess'           => false,
			'readable'           => [],
			'readableOverflow'   => 0,
			'writable'           => [],
			'writableOverflow'   => 0,
			'deleteOnly'         => [],
			'deleteOnlyOverflow' => 0,
		];

		$ref    = OAuthUserRef::parse($grant->userId, $authCollection);
		$refKey = (string)$ref;

		if (!isset($authorityCache[$refKey])) {
			$exists                  = $this->accessControlService->userExists($ref);
			$authorityCache[$refKey] = [
				'exists'    => $exists,
				'authority' => $exists ? $this->accessControlService->authorityFor($ref) : UserAuthority::denied(),
			];
		}

		$cached = $authorityCache[$refKey];

		if (!$cached['exists']) {
			return ['userMissing' => true] + $empty;
		}

		$authority      = $cached['authority'];
		$expandedScopes = $this->oauthScopeRegistry->expand($grant->scopes);

		// ADMIN-persona elevation mirrors McpAuth::resolvePersona(): identity
		// (admin-group membership) AND scope (cms:admin) — never either alone.
		if ($authority->isAdmin && in_array('cms:admin', $expandedScopes, true)) {
			return ['fullAdmin' => true] + $empty;
		}

		$hasReadScope  = in_array('cms:read', $expandedScopes, true);
		$hasWriteScope = in_array('cms:write', $expandedScopes, true);

		$readable   = [];
		$writable   = [];
		$deleteOnly = [];

		foreach ($allCollections as $collection) {
			// Read mirrors PersonaContext::canReadCollection(): public
			// exposure is reachable with NO scope at all (an AUTHENTICATED
			// caller must never read LESS than an anonymous one would), while
			// a group read grant additionally requires the cms:read scope.
			$publiclyReadable = $this->mcpSchemaResolver->isAccessibleTo($collection, 'public');
			$groupReadable    = $hasReadScope && $authority->canCollection('read', $collection->id);

			if ($publiclyReadable || $groupReadable) {
				$readable[] = $collection->id;
			}

			// Write mirrors ObjectTools::requireExposed(): every MCP write
			// tool demands mcp.access expose the collection to at least an
			// AUTHENTICATED persona BEFORE the group grant is even checked.
			// mcp.access defaults to 'admin' (not exposed) when the operator
			// never set it — practically every collection on a fresh site —
			// so a group write grant alone overstates reach without this gate.
			if ($hasWriteScope && $this->mcpSchemaResolver->isAccessibleTo($collection, 'authenticated')) {
				// create_object/update_object need create/update; MCP has no
				// delete tool. A group that only grants delete (no create or
				// update) can still delete over REST but can't be handed
				// anything an AI assistant would call "write" — list it
				// separately rather than folding it into the write badge.
				if ($authority->canCollection('create', $collection->id) || $authority->canCollection('update', $collection->id)) {
					$writable[] = $collection->id;
				} elseif ($authority->canCollection('delete', $collection->id)) {
					$deleteOnly[] = $collection->id;
				}
			}
		}

		sort($readable);
		sort($writable);
		sort($deleteOnly);

		return [
			'fullAdmin'          => false,
			'userMissing'        => false,
			'noAccess'           => $readable === [] && $writable === [] && $deleteOnly === [],
			'readable'           => array_slice($readable, 0, self::EFFECTIVE_REACH_BADGE_CAP),
			'readableOverflow'   => max(0, count($readable) - self::EFFECTIVE_REACH_BADGE_CAP),
			'writable'           => array_slice($writable, 0, self::EFFECTIVE_REACH_BADGE_CAP),
			'writableOverflow'   => max(0, count($writable) - self::EFFECTIVE_REACH_BADGE_CAP),
			'deleteOnly'         => array_slice($deleteOnly, 0, self::EFFECTIVE_REACH_BADGE_CAP),
			'deleteOnlyOverflow' => max(0, count($deleteOnly) - self::EFFECTIVE_REACH_BADGE_CAP),
		];
	}

	/**
	 * Create the default collections.
	 *
	 * Driven by an explicit list rather than every reserved schema: schemas that
	 * exist only to be embedded via `schemaref` (automation triggers, the MCP
	 * sub-objects, sitemap meta) would otherwise each get a junk top-level
	 * collection. See SchemaData::DEFAULT_COLLECTIONS.
	 */
	private function createDefaultCollections(): void
	{
		foreach (SchemaData::DEFAULT_COLLECTIONS as $schemaId) {
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
			if (!$collection instanceof CollectionData) {
				continue;
			}

			try {
				$index = $this->indexReader->fetchIndex($id);
			} catch (\Throwable) {
				$index = null;
			}

			$objects = [];
			if ($index instanceof \TotalCMS\Domain\Index\Data\IndexData) {
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
