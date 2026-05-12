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
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
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
		private BuilderInstaller $builderInstaller,
		private SchemaLister $schemaLister,
		private RssImporter $rssImporter,
		private EditionFeatureService $editionFeatures,
		private SettingsFetcher $settingsFetcher,
		private TemplateLister $templateLister,
		private UpdateChecker $updateChecker,
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
				'settings'  => $this->settingsFetcher->loadSection('sync'),
				'schemas'   => $this->schemaLister->listCustomSchemas(),
				'templates' => $this->templateLister->listBuilderTemplates(null, true),
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
}
