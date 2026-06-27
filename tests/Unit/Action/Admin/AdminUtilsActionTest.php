<?php

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Admin\AdminUtilsAction;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Domain\Twig\Service\TwigLintService;
use TotalCMS\Domain\Visualizer\Service\VisualizerService;
use TotalCMS\Renderer\TwigRenderer;

final class AdminUtilsActionTest extends TestCase
{
	private AdminUtilsAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $twigEngine;
	private \PHPUnit\Framework\MockObject\MockObject $twigLintService;
	private \PHPUnit\Framework\MockObject\MockObject $apiKeyFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $accessGroupLister;
	private \PHPUnit\Framework\MockObject\MockObject $collectionLister;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $indexReader;
	private \PHPUnit\Framework\MockObject\MockObject $builderInstaller;
	private \PHPUnit\Framework\MockObject\MockObject $schemaLister;
	private \PHPUnit\Framework\MockObject\MockObject $rssImporter;
	private \PHPUnit\Framework\MockObject\MockObject $editionFeatures;
	private \PHPUnit\Framework\MockObject\MockObject $settingsFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $templateLister;
	private \PHPUnit\Framework\MockObject\MockObject $updateChecker;
	private \PHPUnit\Framework\MockObject\MockObject $extensionManager;
	private OAuthClientRepository $oauthClientRepository;
	private OAuthGrantRepository $oauthGrantRepository;
	private OAuthScopeRegistry $oauthScopeRegistry;
	private string $oauthClientsTmpFile;
	private string $oauthGrantsTmpFile;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;
	private \PHPUnit\Framework\MockObject\MockObject $visualizerService;

	protected function setUp(): void
	{
		$this->renderer              = $this->createMock(TwigRenderer::class);
		$this->twigEngine            = $this->createMock(TwigEngine::class);
		$this->twigLintService       = $this->createMock(TwigLintService::class);
		$this->apiKeyFetcher         = $this->createMock(ApiKeyFetcher::class);
		$this->accessGroupLister     = $this->createMock(AccessGroupLister::class);
		$this->collectionLister      = $this->createMock(CollectionLister::class);
		$this->collectionFetcher     = $this->createMock(CollectionFetcher::class);
		$this->indexReader           = $this->createMock(IndexReader::class);
		$this->builderInstaller      = $this->createMock(BuilderInstaller::class);
		$this->schemaLister          = $this->createMock(SchemaLister::class);
		$this->rssImporter           = $this->createMock(RssImporter::class);
		$this->editionFeatures       = $this->createMock(EditionFeatureService::class);
		$this->settingsFetcher       = $this->createMock(SettingsFetcher::class);
		$this->templateLister        = $this->createMock(TemplateLister::class);
		$this->updateChecker         = $this->createMock(\TotalCMS\Domain\Update\Service\UpdateChecker::class);
		$this->extensionManager      = $this->createMock(\TotalCMS\Domain\Extension\Service\ExtensionManager::class);
		// OAuth repositories + scope registry are all final classes (can't be
		// doubled). Use real instances pointed at empty tmp files — the tests
		// here exercise the action's plumbing, not OAuth data shape.
		$this->oauthClientsTmpFile   = sys_get_temp_dir() . '/oauth-clients-utils-test-' . uniqid() . '.json';
		$this->oauthGrantsTmpFile    = sys_get_temp_dir() . '/oauth-grants-utils-test-' . uniqid() . '.json';
		$this->oauthClientRepository = new OAuthClientRepository($this->oauthClientsTmpFile);
		$this->oauthGrantRepository  = new OAuthGrantRepository($this->oauthGrantsTmpFile);
		$this->oauthScopeRegistry    = new OAuthScopeRegistry();
		$this->request               = $this->createMock(ServerRequestInterface::class);
		$this->response              = $this->createMock(ResponseInterface::class);
		$this->visualizerService     = $this->createMock(VisualizerService::class);

		$this->action = new AdminUtilsAction(
			$this->renderer,
			$this->twigEngine,
			$this->twigLintService,
			$this->apiKeyFetcher,
			$this->accessGroupLister,
			$this->collectionLister,
			$this->collectionFetcher,
			$this->indexReader,
			$this->builderInstaller,
			$this->schemaLister,
			$this->rssImporter,
			$this->editionFeatures,
			$this->settingsFetcher,
			$this->templateLister,
			$this->updateChecker,
			$this->oauthClientRepository,
			$this->oauthGrantRepository,
			$this->oauthScopeRegistry,
			$this->extensionManager,
			$this->visualizerService,
		);
	}

	protected function tearDown(): void
	{
		if (is_file($this->oauthClientsTmpFile)) {
			unlink($this->oauthClientsTmpFile);
		}
		if (is_file($this->oauthGrantsTmpFile)) {
			unlink($this->oauthGrantsTmpFile);
		}
	}

	/**
	 * Set up routing context attributes on the mocked request.
	 */
	private function setupRoutingContext(?string $routeName = null): void
	{
		$routeParser    = $this->createMock(\Slim\Interfaces\RouteParserInterface::class);
		$routingResults = $this->createMock(\Slim\Routing\RoutingResults::class);
		$route          = $routeName !== null
			? $this->createMock(\Slim\Routing\Route::class)
			: null;

		if ($route instanceof \PHPUnit\Framework\MockObject\MockObject) {
			$route->method('getName')->willReturn($routeName);
		}

		$this->request->method('getAttribute')
			->willReturnCallback(fn (string $name): \PHPUnit\Framework\MockObject\MockObject|string|null => match ($name) {
				'__routeParser__'    => $routeParser,
				'__routingResults__' => $routingResults,
				'__basePath__'       => '',
				'__route__'          => $route,
				default              => null,
			});
	}

	public function testRendersUtilsTemplateWithDefaultPage(): void
	{
		$this->setupRoutingContext();

		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['page'] === 'index'
						&& $data['url']['page'] === 'utils')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesTwigPlaygroundPostRequest(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/twig-playground');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getParsedBody')->willReturn([
			'twig' => '{{ "Hello World" }}',
		]);

		$this->twigEngine->expects($this->once())
			->method('renderString')
			->with('{{ "Hello World" }}')
			->willReturn('Hello World');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['page'] === 'twig-playground'
						&& $data['results'] === 'Hello World')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'twig-playground']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesTwigPlaygroundErrors(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/twig-playground');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getParsedBody')->willReturn([
			'twig' => '{{ invalid syntax',
		]);

		$this->twigEngine->expects($this->once())
			->method('renderString')
			->willThrowException(new \Exception('Syntax error'));

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => str_contains((string)$data['results'], 'error')
						&& str_contains((string)$data['results'], 'Syntax error'))
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'twig-playground']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesGetRequestWithoutProcessing(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/twig-playground');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');

		$this->twigEngine->expects($this->never())->method('renderString');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['results'] === '')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'twig-playground']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesPostDataWhenMethodIsPost(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils');
		$uri->method('getQuery')->willReturn('');

		$postData = ['key' => 'value', 'foo' => 'bar'];

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getParsedBody')->willReturn($postData);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['postData'] === $postData)
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesEmptyPostDataForGetRequest(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['postData'] === [])
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesProjectSetupPage(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/project-setup');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['page'] === 'project-setup'
						&& isset($data['totalcms1DetectionData']))
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'project-setup']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testNullDetectionDataForNonProjectSetupPages(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/other-page');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['totalcms1DetectionData'] === null)
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'other-page']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testOnlyProcessesTwigPlaygroundPage(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/other-page');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getParsedBody')->willReturn([
			'twig' => '{{ "Should not process" }}',
		]);

		// TwigEngine should not be called for non-twig-playground pages
		$this->twigEngine->expects($this->never())->method('renderString');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('template')->willReturn($expectedResponse);

		($this->action)($this->request, $this->response, ['page' => 'other-page']);
	}

	public function testRendersOAuthClientsPageWithClientGroupsAndGrantCounts(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/oauth-clients');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');
		$this->request->method('getQueryParams')->willReturn([]);

		// Seed one static client and one dynamic client
		$staticClient = new \TotalCMS\Domain\OAuth\Data\OAuthClientData(
			id: 'static-1',
			name: 'Static App',
			secretHash: '$2y$12$hash1',
			redirectUris: ['https://example.com/cb'],
			scopes: ['cms:read'],
			isDynamic: false,
			isConfidential: true,
			createdAt: '2026-01-01T00:00:00Z',
			createdBy: 'admin',
		);
		$dynamicClient = new \TotalCMS\Domain\OAuth\Data\OAuthClientData(
			id: 'dynamic-1',
			name: 'Dynamic App',
			secretHash: '$2y$12$hash2',
			redirectUris: ['https://dynamic.example.com/cb'],
			scopes: ['cms:read'],
			isDynamic: true,
			isConfidential: true,
			createdAt: '2026-01-01T00:00:00Z',
			createdBy: 'admin',
		);
		$this->oauthClientRepository->save($staticClient);
		$this->oauthClientRepository->save($dynamicClient);

		// Seed one grant per client
		$grant1 = new \TotalCMS\Domain\OAuth\Data\OAuthGrantData(
			id: 'grant-1',
			clientId: 'static-1',
			userId: 'user@example.com',
			scopes: ['cms:read'],
			refreshTokenHash: 'hash-a',
			issuedAt: '2026-01-01T00:00:00Z',
			expiresAt: '2027-01-01T00:00:00Z',
		);
		$grant2 = new \TotalCMS\Domain\OAuth\Data\OAuthGrantData(
			id: 'grant-2',
			clientId: 'dynamic-1',
			userId: 'user@example.com',
			scopes: ['cms:read'],
			refreshTokenHash: 'hash-b',
			issuedAt: '2026-01-01T00:00:00Z',
			expiresAt: '2027-01-01T00:00:00Z',
		);
		$this->oauthGrantRepository->save($grant1);
		$this->oauthGrantRepository->save($grant2);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(function (array $data): bool {
					if ($data['page'] !== 'oauth-clients') {
						return false;
					}
					$clients = $data['oauthClients'] ?? null;
					if (!is_array($clients)) {
						return false;
					}
					// One static client with one grant
					if (count($clients['static']) !== 1) {
						return false;
					}
					// One dynamic client with one grant
					if (count($clients['dynamic']) !== 1) {
						return false;
					}
					$staticRow  = $clients['static'][0];
					$dynamicRow = $clients['dynamic'][0];

					return $staticRow['client']->id === 'static-1'
						&& $staticRow['grantCount'] === 1
						&& $dynamicRow['client']->id === 'dynamic-1'
						&& $dynamicRow['grantCount'] === 1;
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'oauth-clients']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testRendersOAuthClientsNewFormWithScopeList(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/oauth-clients');
		$uri->method('getQuery')->willReturn('action=new');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');
		$this->request->method('getQueryParams')->willReturn(['action' => 'new']);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(function (array $data): bool {
					if ($data['page'] !== 'oauth-clients') {
						return false;
					}
					$form = $data['oauthClientsForm'] ?? null;
					if (!is_array($form) || !isset($form['scopes'])) {
						return false;
					}

					// OAuthScopeRegistry defines 6 T3 scopes
					// (cms:read, cms:write, cms:admin, mcp:tools, mcp:resources, mcp:prompts)
					return count($form['scopes']) === 6;
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'oauth-clients', 'action' => 'new']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testRendersOAuthGrantsPageWithGrantsJoinedToClientNames(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/oauth-grants');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');
		$this->request->method('getQueryParams')->willReturn([]);

		// Seed a client and a grant
		$client = new \TotalCMS\Domain\OAuth\Data\OAuthClientData(
			id: 'client-xyz',
			name: 'My OAuth App',
			secretHash: '$2y$12$hash',
			redirectUris: ['https://example.com/cb'],
			scopes: ['cms:read'],
			isDynamic: false,
			isConfidential: true,
			createdAt: '2026-01-01T00:00:00Z',
			createdBy: 'admin',
		);
		$grant = new \TotalCMS\Domain\OAuth\Data\OAuthGrantData(
			id: 'grant-xyz',
			clientId: 'client-xyz',
			userId: 'user@example.com',
			scopes: ['cms:read'],
			refreshTokenHash: 'hash-x',
			issuedAt: '2026-01-01T00:00:00Z',
			expiresAt: '2027-01-01T00:00:00Z',
		);
		$this->oauthClientRepository->save($client);
		$this->oauthGrantRepository->save($grant);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(function (array $data): bool {
					if ($data['page'] !== 'oauth-grants') {
						return false;
					}
					$grants = $data['oauthGrants'] ?? null;
					if (!is_array($grants) || count($grants) !== 1) {
						return false;
					}
					$row = $grants[0];

					return $row['grant']->id === 'grant-xyz'
						&& $row['clientName'] === 'My OAuth App';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, ['page' => 'oauth-grants']);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesUrlData(): void
	{
		$this->setupRoutingContext();
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/utils/cache');
		$uri->method('getQuery')->willReturn('action=clear');

		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getMethod')->willReturn('GET');

		$args = ['page' => 'cache'];

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/utils.twig',
				$this->callback(fn ($data): bool => $data['url']['path'] === '/admin/utils/cache'
						&& $data['url']['query'] === 'action=clear'
						&& $data['url']['params'] === $args
						&& $data['url']['page'] === 'utils')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($expectedResponse, $result);
	}
}
