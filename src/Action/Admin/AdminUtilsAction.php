<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Domain\Twig\Service\TwigLintService;
use TotalCMS\Renderer\TwigRenderer;

readonly class AdminUtilsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private TwigEngine $twigEngine,
		private TwigLintService $twigLintService,
		private ApiKeyFetcher $apiKeyFetcher,
		private AccessGroupLister $accessGroupLister,
		private CollectionRepository $collectionRepository,
		private CollectionFetcher $collectionFetcher,
		private SchemaLister $schemaLister,
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

		$action  = $args['action'] ?? '';
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
		if ($page === 'project-setup') {
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
			'collections' => $this->collectionRepository->listAllCollections(),
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
			// Skip blog-legacy schema
			if ($schemaId === 'blog-legacy') {
				continue;
			}
			$this->collectionFetcher->fetchOrCreateReserved($schemaId);
		}
	}

	/**
	 * Lint a Twig file for syntax errors.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @return array{success: bool, error?: array{message: string, line: int, context: string}, file: string}
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

		return $this->twigLintService->lintFile($realPath);
	}
}
