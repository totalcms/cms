<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Sync;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Sync\Service\SyncService;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;
use TotalCMS\Support\OperationResult;

final class SyncServiceTest extends TestCase
{
	private SyncService $service;
	private \PHPUnit\Framework\MockObject\MockObject $exporter;
	private \PHPUnit\Framework\MockObject\MockObject $importer;
	private \PHPUnit\Framework\MockObject\MockObject $httpClient;
	private \PHPUnit\Framework\MockObject\MockObject $paths;

	protected function setUp(): void
	{
		$this->exporter   = $this->createMock(JumpStartExporter::class);
		$this->importer   = $this->createMock(JumpStartImporter::class);
		$this->httpClient = $this->createMock(HttpClientInterface::class);
		// Admin-first by default — template sync behaves as before.
		$this->paths = $this->createMock(BuilderTemplatePaths::class);
		$this->paths->method('isProjectManaged')->willReturn(false);

		$this->service = new SyncService(
			$this->exporter,
			$this->importer,
			$this->httpClient,
			$this->paths,
		);
	}

	// ==================== Push Tests ====================

	public function testPushReturnsNothingWhenEmpty(): void
	{
		$emptyData = new JumpStartData();

		$this->exporter->method('exportSyncData')->willReturn($emptyData);
		$this->exporter->method('setMetadata');

		$result = $this->service->push('https://example.com', 'key');

		expect($result->success)->toBeTrue();
		expect($result->data['schemas'])->toBe(0);
		expect($result->data['templates'])->toBe(0);
		expect($result->message)->toContain('Nothing to push');
	}

	public function testPushExcludesTemplatesWhenGitManaged(): void
	{
		// Git-managed project: templates travel by git, so push must force the
		// template filter to [] ("none") regardless of what was requested.
		$paths = $this->createMock(BuilderTemplatePaths::class);
		$paths->method('isProjectManaged')->willReturn(true);

		$exporter = $this->createMock(JumpStartExporter::class);
		$exporter->method('setMetadata');
		$exporter->expects($this->once())
			->method('exportSyncData')
			->with(null, [], null)
			->willReturn(new JumpStartData());

		$service = new SyncService($exporter, $this->importer, $this->httpClient, $paths);

		// Caller asked for "all templates" (null) — git-management overrides it.
		$service->push('https://example.com', 'key');
	}

	public function testPushSendsDataToRemote(): void
	{
		$jumpstart = new JumpStartData();
		$jumpstart->addSchema(['id' => 'products', 'properties' => ['name' => ['type' => 'string']]]);
		$jumpstart->addTemplate(['id' => 'blog-post', 'template' => '<h1>Blog</h1>']);

		$this->exporter->method('exportSyncData')->willReturn($jumpstart);
		$this->exporter->method('setMetadata');

		$this->httpClient->expects($this->once())
			->method('request')
			->with(
				'POST',
				'https://example.com/api/sync/import',
				$this->callback(function (array $options): bool {
					expect($options['body'])->toContain('products');
					// SyncService sends the API key via X-API-Key rather than
					// `Authorization: Bearer` so OAuthBearerMiddleware (mounted
					// on /api/) doesn't intercept it and try to validate as JWT.
					expect($options['headers'][0])->toBe('X-API-Key: test-key');

					return true;
				})
			)
			->willReturn(new HttpResponse(200, json_encode([
				'success' => true,
				'summary' => ['schemas_created' => 1, 'templates_created' => 1],
			])));

		$result = $this->service->push('https://example.com', 'test-key');

		expect($result->success)->toBeTrue();
		expect($result->message)->toBe('Push complete.');
		expect($result->data['schemas'])->toBe(1);
		expect($result->data['templates'])->toBe(1);
	}

	public function testPushPassesFiltersToExporter(): void
	{
		$jumpstart = new JumpStartData();
		$jumpstart->addSchema(['id' => 'products', 'properties' => []]);

		$this->exporter->expects($this->once())
			->method('exportSyncData')
			->with(['products'], ['blog-post'])
			->willReturn($jumpstart);
		$this->exporter->method('setMetadata');

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, '{"success":true}'));

		$this->service->push('https://example.com', 'key', ['products'], ['blog-post']);
	}

	public function testPushThrowsOnRemoteError(): void
	{
		$jumpstart = new JumpStartData();
		$jumpstart->addSchema(['id' => 'products', 'properties' => []]);

		$this->exporter->method('exportSyncData')->willReturn($jumpstart);
		$this->exporter->method('setMetadata');

		$this->httpClient->method('request')->willReturn(new HttpResponse(401, '{"error":"Unauthorized"}'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Push failed (HTTP 401)');

		$this->service->push('https://example.com', 'bad-key');
	}

	public function testPushThrowsOnHttpException(): void
	{
		$jumpstart = new JumpStartData();
		$jumpstart->addSchema(['id' => 'products', 'properties' => []]);

		$this->exporter->method('exportSyncData')->willReturn($jumpstart);
		$this->exporter->method('setMetadata');

		$this->httpClient->method('request')->willThrowException(new \RuntimeException('Connection refused'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Connection refused');

		$this->service->push('https://example.com', 'key');
	}

	// ==================== Pull Tests ====================

	public function testPullImportsDataLocally(): void
	{
		$remotePayload = json_encode([
			'schemas'   => [['id' => 'products', 'properties' => []]],
			'templates' => [['id' => 'blog-post', 'template' => '<h1>Blog</h1>']],
		]);

		// Pull fetches from the dedicated /sync/export route (one "Sync
		// Manager" API-key grant covers both directions).
		$this->httpClient->expects($this->once())
			->method('request')
			->with('GET', 'https://example.com/api/sync/export', $this->anything())
			->willReturn(new HttpResponse(200, (string)$remotePayload));

		// Pull is server-authoritative for the local copy: pass through to
		// the importer in upsert mode so an existing local row is overwritten
		// rather than silently skipped (which is the default starter-kit
		// behaviour of importFromDefinition).
		$this->importer->expects($this->once())
			->method('importFromDefinition')
			->with($this->anything(), true)
			->willReturn(OperationResult::success('Import complete.', [
				'results' => [],
				'errors'  => [],
				'summary' => ['schemas_created' => 1, 'templates_created' => 1],
			]));

		$result = $this->service->pull('https://example.com', 'key');

		expect($result->success)->toBeTrue();
		expect($result->message)->toBe('Pull complete.');
		expect($result->data['schemas'])->toBe(1);
		expect($result->data['templates'])->toBe(1);
	}

	public function testPullFallsBackToLegacyExportRouteOnFourOhFour(): void
	{
		// A remote on an older release has no /sync/export route (404), and a
		// key created before the "Sync Manager" endpoint option may grant
		// /export but not /sync (403). Either way pull retries the legacy
		// jumpstart export route before giving up.
		$remotePayload = (string)json_encode([
			'schemas'   => [['id' => 'products', 'properties' => []]],
			'templates' => [],
		]);

		$this->httpClient->expects($this->exactly(2))
			->method('request')
			->willReturnCallback(function (string $method, string $url) use ($remotePayload): HttpResponse {
				return str_ends_with($url, '/api/sync/export')
					? new HttpResponse(404, '{"error":{"message":"Not found"}}')
					: new HttpResponse(200, $remotePayload);
			});

		$payload = $this->service->fetchRemoteSyncData('https://example.com', 'key');

		expect($payload['schemas'])->toHaveCount(1);
	}

	public function testPullReturnsNothingWhenRemoteEmpty(): void
	{
		$this->httpClient->method('request')->willReturn(new HttpResponse(200, json_encode([
			'schemas'   => [],
			'templates' => [],
		])));

		$this->importer->expects($this->never())->method('importFromDefinition');

		$result = $this->service->pull('https://example.com', 'key');

		expect($result->success)->toBeTrue();
		expect($result->data['schemas'])->toBe(0);
		expect($result->data['templates'])->toBe(0);
		expect($result->message)->toContain('Nothing to pull');
	}

	public function testPullFiltersSchemas(): void
	{
		$remotePayload = json_encode([
			'schemas'   => [
				['id' => 'products', 'properties' => []],
				['id' => 'invoice', 'properties' => []],
			],
			'templates' => [],
		]);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)$remotePayload));

		$this->importer->expects($this->once())
			->method('importFromDefinition')
			->with($this->callback(function (array $payload): bool {
				expect($payload['schemas'])->toHaveCount(1);
				expect($payload['schemas'][0]['id'])->toBe('products');

				return true;
			}))
			->willReturn(OperationResult::success('Import complete.', ['results' => [], 'errors' => [], 'summary' => []]));

		$this->service->pull('https://example.com', 'key', ['products']);
	}

	public function testPullFiltersCollectionsByMapKeys(): void
	{
		// objects[] entries carry their owning collection in `collection`.
		// The per-collection map drops any entry whose collection key
		// isn't in the map at all — that's how "I didn't pick this one"
		// is encoded by the form parser.
		$remotePayload = json_encode([
			'schemas'   => [],
			'templates' => [],
			'objects'   => [
				['collection' => 'builder-pages', 'id' => 'home', 'data' => []],
				['collection' => 'mailer',        'id' => 'contact', 'data' => []],
				['collection' => 'mcp-prompt',    'id' => 'greet', 'data' => []],
			],
		]);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)$remotePayload));

		$this->importer->expects($this->once())
			->method('importFromDefinition')
			->with($this->callback(function (array $payload): bool {
				expect($payload['objects'])->toHaveCount(1);
				expect($payload['objects'][0]['collection'])->toBe('builder-pages');

				return true;
			}))
			->willReturn(OperationResult::success('Import complete.', ['results' => [], 'errors' => [], 'summary' => []]));

		// Only builder-pages is in the map (with value null = all of its objects).
		// mailer and mcp-prompt are absent → dropped.
		$this->service->pull('https://example.com', 'key', null, null, ['builder-pages' => null]);
	}

	public function testPullFiltersObjectsWithinACollection(): void
	{
		// When the operator picks "specific" with a list of ids, the
		// matching objects survive and the rest are dropped — even within
		// a collection that's in the map.
		$remotePayload = json_encode([
			'schemas'   => [],
			'templates' => [],
			'objects'   => [
				['collection' => 'builder-pages', 'id' => 'home',  'data' => []],
				['collection' => 'builder-pages', 'id' => 'about', 'data' => []],
				['collection' => 'builder-pages', 'id' => 'blog',  'data' => []],
			],
		]);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)$remotePayload));

		$this->importer->expects($this->once())
			->method('importFromDefinition')
			->with($this->callback(function (array $payload): bool {
				expect($payload['objects'])->toHaveCount(2);
				$ids = array_map(fn (array $o): string => (string)$o['id'], $payload['objects']);
				expect($ids)->toContain('home');
				expect($ids)->toContain('blog');

				return true;
			}))
			->willReturn(OperationResult::success('Import complete.', ['results' => [], 'errors' => [], 'summary' => []]));

		$this->service->pull('https://example.com', 'key', null, null, [
			'builder-pages' => ['home', 'blog'],
		]);
	}

	public function testPushPassesCollectionsMapToExporter(): void
	{
		$jumpstart = new JumpStartData();
		$jumpstart->addSchema(['id' => 'products', 'properties' => []]);

		$map = [
			'builder-pages' => null,            // all pages
			'mcp-prompt'    => ['greet'],       // just the greet prompt
		];

		$this->exporter->expects($this->once())
			->method('exportSyncData')
			->with(null, null, $map)
			->willReturn($jumpstart);
		$this->exporter->method('setMetadata');

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, '{"success":true}'));

		$this->service->push('https://example.com', 'key', null, null, $map);
	}

	public function testPullCountsCollectionObjects(): void
	{
		$remotePayload = json_encode([
			'schemas'   => [],
			'templates' => [],
			'objects'   => [
				['collection' => 'builder-pages', 'id' => 'home', 'data' => []],
				['collection' => 'builder-pages', 'id' => 'about', 'data' => []],
			],
		]);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)$remotePayload));

		$this->importer->method('importFromDefinition')
			->willReturn(OperationResult::success('Import complete.', ['results' => [], 'errors' => [], 'summary' => []]));

		$result = $this->service->pull('https://example.com', 'key');

		expect($result->success)->toBeTrue();
		expect($result->data['collections'])->toBe(2);
	}

	public function testPullFiltersTemplates(): void
	{
		$remotePayload = json_encode([
			'schemas'   => [],
			'templates' => [
				['id' => 'blog-post', 'template' => '<h1>Blog</h1>'],
				['id' => 'sidebar', 'template' => '<aside>Side</aside>'],
			],
		]);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)$remotePayload));

		$this->importer->expects($this->once())
			->method('importFromDefinition')
			->with($this->callback(function (array $payload): bool {
				expect($payload['templates'])->toHaveCount(1);
				expect($payload['templates'][0]['id'])->toBe('sidebar');

				return true;
			}))
			->willReturn(OperationResult::success('Import complete.', ['results' => [], 'errors' => [], 'summary' => []]));

		$this->service->pull('https://example.com', 'key', null, ['sidebar']);
	}

	public function testPullThrowsOnRemoteError(): void
	{
		$this->httpClient->method('request')->willReturn(new HttpResponse(500, 'Internal Server Error'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Pull failed (HTTP 500)');

		$this->service->pull('https://example.com', 'key');
	}

	public function testPullThrowsOnInvalidJson(): void
	{
		$this->httpClient->method('request')->willReturn(new HttpResponse(200, 'not json'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('invalid response');

		$this->service->pull('https://example.com', 'key');
	}

	// ==================== FetchRemoteSyncData Tests ====================

	public function testFetchRemoteSyncDataReturnsFilteredPayload(): void
	{
		$remotePayload = json_encode([
			'schemas'   => [
				['id' => 'products'],
				['id' => 'invoice'],
			],
			'templates' => [
				['id' => 'blog-post'],
				['id' => 'sidebar'],
			],
		]);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)$remotePayload));

		$result = $this->service->fetchRemoteSyncData('https://example.com', 'key', ['products'], ['sidebar']);

		expect($result['schemas'])->toHaveCount(1);
		expect($result['schemas'][0]['id'])->toBe('products');
		expect($result['templates'])->toHaveCount(1);
		expect($result['templates'][0]['id'])->toBe('sidebar');
	}

	public function testFetchRemoteSyncDataReturnsAllWhenNoFilters(): void
	{
		$remotePayload = json_encode([
			'schemas'   => [['id' => 'products'], ['id' => 'invoice']],
			'templates' => [['id' => 'blog-post']],
		]);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)$remotePayload));

		$result = $this->service->fetchRemoteSyncData('https://example.com', 'key');

		expect($result['schemas'])->toHaveCount(2);
		expect($result['templates'])->toHaveCount(1);
	}
}
