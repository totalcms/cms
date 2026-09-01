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
			new \TotalCMS\Domain\Sync\Service\SyncDiffService(),
		);
	}

	// ==================== Diff Tests ====================

	public function testDiffComparesLocalExportAgainstRemotePayload(): void
	{
		// The single orchestration shared by CLI dry-runs and the admin
		// Sync Manager: export local, fetch remote, hand both to
		// SyncDiffService.
		$local = new JumpStartData('Local', '');
		$local->addSchema(['id' => 'products', 'properties' => ['name' => []]]);
		$this->exporter->method('exportSyncData')->willReturn($local);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)json_encode([
			'schemas' => [[
				'id'         => 'products',
				'properties' => ['name' => [], 'price' => []],
				'updated'    => '2026-07-29T10:00:00+00:00',
			]],
		])));

		$diff = $this->service->diff('https://example.com', 'key');

		expect($diff['schemas']['products']['status'])->toBe(\TotalCMS\Domain\Sync\Service\SyncDiffService::DIFFERS);
		expect($diff['schemas']['products']['newer'])->toBe('remote');
		expect($diff['templates'])->toBe([]);
		expect($diff['objects'])->toBe([]);
	}

	public function testDiffIgnoresPlaygroundCollectionFromAnOlderRemote(): void
	{
		// The local exporter no longer emits the Twig Playground's collection,
		// but a remote on an older release still does. Left alone it reports a
		// permanent "only on production" that the operator cannot select (the
		// UI lists local collections) and would never want to resolve.
		$this->exporter->method('exportSyncData')->willReturn(new JumpStartData('Local', ''));

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)json_encode([
			'collections' => [
				'reserved' => [
					['id' => 'playground', 'schema' => 'playground'],
					['id' => 'mailer', 'schema' => 'mailer'],
				],
				'custom' => [],
			],
		])));

		$diff = $this->service->diff('https://example.com', 'key');

		expect($diff['collections'])->not->toHaveKey('playground');
		expect($diff['collections'])->toHaveKey('mailer');
	}

	public function testPullDoesNotImportPlaygroundCollectionFromAnOlderRemote(): void
	{
		// Same asymmetry on the write path: pulling from an older remote must
		// not create the scratchpad collection on this install.
		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)json_encode([
			'collections' => [
				'reserved' => [
					['id' => 'playground', 'schema' => 'playground'],
					['id' => 'mailer', 'schema' => 'mailer'],
				],
				'custom' => [],
			],
		])));

		$imported = null;
		$this->importer->method('importFromDefinition')
			->willReturnCallback(function (array $payload) use (&$imported) {
				$imported = $payload;

				return OperationResult::success('ok', []);
			});

		$this->service->pull('https://example.com', 'key');

		expect(array_column($imported['collections']['reserved'], 'id'))->toBe(['mailer']);
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

		$service = new SyncService($exporter, $this->importer, $this->httpClient, $paths, new \TotalCMS\Domain\Sync\Service\SyncDiffService());

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

	public function testPushReportsFailureWhenTheRemoteRejectsTheContents(): void
	{
		// The receive endpoint answers 200 even when the importer refused every
		// item — it reports per-item failures in the body rather than throwing.
		// Reading only the status code turned a wholly failed push into
		// "Push complete.", which is how a reserved-name refusal reached a user
		// as a clean result.
		$jumpstart = new JumpStartData();
		$jumpstart->addCustomCollection(['id' => 'extensions', 'schema' => 'extension', 'name' => 'Extensions']);

		$this->exporter->method('exportSyncData')->willReturn($jumpstart);
		$this->exporter->method('setMetadata');

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)json_encode([
			'success' => false,
			'message' => 'Import completed with errors',
			'results' => [],
			'errors'  => ['Collection extensions: Cannot save collection with a reserved name'],
		])));

		$result = $this->service->push('https://example.com', 'key');

		expect($result->success)->toBeFalse();
		expect($result->error)->toContain('Cannot save collection with a reserved name');
		expect($result->data['remote_result']['errors'])->toHaveCount(1);
	}

	public function testPushCountsCollectionsSeparatelyFromObjects(): void
	{
		// A settings-only push carries collections and nothing else. Counting
		// `objects` under the `collections` key reported 0 here, so a push that
		// did real work was indistinguishable from a no-op.
		$jumpstart = new JumpStartData();
		$jumpstart->addCustomCollection(['id' => 'addons', 'schema' => 'extension', 'name' => 'Extensions']);

		$this->exporter->method('exportSyncData')->willReturn($jumpstart);
		$this->exporter->method('setMetadata');

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, '{"success":true,"errors":[]}'));

		$result = $this->service->push('https://example.com', 'key');

		expect($result->success)->toBeTrue();
		expect($result->data['collections'])->toBe(1);
		expect($result->data['objects'])->toBe(0);
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

	private function capturingClientReturns(?string &$captured): void
	{
		$this->httpClient->method('request')
			->willReturnCallback(function (string $method, string $url) use (&$captured): HttpResponse {
				$captured = $url;

				return new HttpResponse(200, (string)json_encode(['success' => true]));
			});
	}

	public function testSeedPushTargetsTheSkipExistingEndpoint(): void
	{
		$payload = new JumpStartData('Local', '');
		$payload->addObject(['collection' => 'blog', 'id' => 'welcome']);
		$this->exporter->method('exportSyncData')->willReturn($payload);

		$captured = null;
		$this->capturingClientReturns($captured);

		$this->service->push('https://prod.example.com', 'k', null, null, null, null, ['blog' => null], false);

		$this->assertSame('https://prod.example.com/api/import/jumpstart', $captured);
	}

	public function testOverwriteSendsSeededObjectsToTheUpsertEndpoint(): void
	{
		$payload = new JumpStartData('Local', '');
		$payload->addObject(['collection' => 'blog', 'id' => 'welcome']);
		$this->exporter->method('exportSyncData')->willReturn($payload);

		$captured = null;
		$this->capturingClientReturns($captured);

		$this->service->push('https://prod.example.com', 'k', null, null, null, null, ['blog' => null], true);

		$this->assertSame('https://prod.example.com/api/sync/import', $captured);
	}

	public function testPushWithoutASeedFilterKeepsTheMirrorEndpoint(): void
	{
		$payload = new JumpStartData('Local', '');
		$payload->addSchema(['id' => 'products', 'properties' => ['name' => []]]);
		$this->exporter->method('exportSyncData')->willReturn($payload);

		$captured = null;
		$this->capturingClientReturns($captured);

		$this->service->push('https://prod.example.com', 'k');

		$this->assertSame('https://prod.example.com/api/sync/import', $captured);
	}

	public function testSeedFilterReachesTheExporter(): void
	{
		$payload = new JumpStartData('Local', '');
		$payload->addObject(['collection' => 'blog', 'id' => 'welcome']);

		$this->exporter->expects($this->once())
			->method('exportSyncData')
			->with(null, null, null, null, ['blog' => ['welcome']])
			->willReturn($payload);

		$captured = null;
		$this->capturingClientReturns($captured);

		$this->service->push('https://prod.example.com', 'k', null, null, null, null, ['blog' => ['welcome']], false);
	}

	/**
	 * Record every outbound request as ['url' => …, 'body' => …] and answer
	 * each one with the next queued response, falling back to a plain 200
	 * success once the queue runs out. capturingClientReturns() only keeps
	 * the LAST url, which cannot tell a one-request push from a two-request
	 * one — the exact distinction the split transport turns on.
	 *
	 * @param list<array{url:string,body:string}> $requests
	 * @param list<HttpResponse>                  $responses
	 */
	private function recordingClient(array &$requests, array $responses = []): void
	{
		$this->httpClient->method('request')
			->willReturnCallback(function (string $method, string $url, array $options) use (&$requests, &$responses): HttpResponse {
				$requests[] = ['url' => $url, 'body' => (string)($options['body'] ?? '')];

				return array_shift($responses) ?? new HttpResponse(200, (string)json_encode(['success' => true]));
			});
	}

	public function testAMixedPushSplitsTheSeededObjectsOntoTheirOwnRequest(): void
	{
		// The reason the split exists. Choosing one endpoint for the whole
		// payload sent the schema, the page and the collection settings
		// through the skip-existing importer as well, which runs with
		// upsert=false: pages already on the target were silently skipped,
		// schemas were overwritten with no backup, and existing collections
		// had their lifetime oid counter recomputed. Mixed payloads are the
		// primary use case, so each half has to keep its own semantics.
		$payload = new JumpStartData('Local', '');
		$payload->addSchema(['id' => 'faq', 'properties' => []]);
		$payload->addTemplate(['id' => 'faq-list', 'template' => '<ul></ul>']);
		$payload->addCustomCollection(['id' => 'faq', 'schema' => 'faq', 'name' => 'FAQ']);
		$payload->addObject(['collection' => 'builder-pages', 'id' => 'home']);
		$payload->addObject(['collection' => 'faq', 'id' => 'what-is-t3']);
		$this->exporter->method('exportSyncData')->willReturn($payload);

		$requests = [];
		$this->recordingClient($requests);

		$result = $this->service->push(
			'https://prod.example.com',
			'k',
			['faq'],
			['faq-list'],
			['builder-pages' => null],
			['faq'],
			['faq' => null],
			false,
		);

		$this->assertCount(2, $requests);
		$this->assertSame('https://prod.example.com/api/sync/import', $requests[0]['url']);
		$this->assertSame('https://prod.example.com/api/import/jumpstart', $requests[1]['url']);

		$mirror = json_decode($requests[0]['body'], true);
		$seed   = json_decode($requests[1]['body'], true);

		// Everything that upserts stays on the mirror leg — including the
		// feature-flag objects, which --pages documents as upserting.
		expect(array_column($mirror['schemas'], 'id'))->toBe(['faq']);
		expect(array_column($mirror['templates'], 'id'))->toBe(['faq-list']);
		expect($mirror['collections']['custom'])->toHaveCount(1);
		expect(array_column($mirror['objects'], 'id'))->toBe(['home']);

		// The seed leg carries the seeded objects and nothing else.
		expect($seed['schemas'])->toBe([]);
		expect($seed['templates'])->toBe([]);
		expect($seed['collections']['custom'])->toBe([]);
		expect(array_column($seed['objects'], 'id'))->toBe(['what-is-t3']);

		expect($result->success)->toBeTrue();
		expect($result->data['objects'])->toBe(2);
		expect($result->data['seeded'])->toBe(1);
	}

	public function testOverwriteKeepsAMixedPushOnOneUpsertRequest(): void
	{
		// --overwrite means every item upserts, so there is nothing to split.
		$payload = new JumpStartData('Local', '');
		$payload->addSchema(['id' => 'faq', 'properties' => []]);
		$payload->addObject(['collection' => 'faq', 'id' => 'what-is-t3']);
		$this->exporter->method('exportSyncData')->willReturn($payload);

		$requests = [];
		$this->recordingClient($requests);

		$this->service->push('https://prod.example.com', 'k', ['faq'], [], [], [], ['faq' => null], true);

		$this->assertCount(1, $requests);
		$this->assertSame('https://prod.example.com/api/sync/import', $requests[0]['url']);

		$body = json_decode($requests[0]['body'], true);
		expect(array_column($body['objects'], 'id'))->toBe(['what-is-t3']);
	}

	public function testASeedOnlyPushSendsJustTheJumpstartRequest(): void
	{
		$payload = new JumpStartData('Local', '');
		$payload->addObject(['collection' => 'blog', 'id' => 'welcome']);
		$this->exporter->method('exportSyncData')->willReturn($payload);

		$requests = [];
		$this->recordingClient($requests);

		$this->service->push('https://prod.example.com', 'k', [], [], [], [], ['blog' => null], false);

		$this->assertCount(1, $requests);
		$this->assertSame('https://prod.example.com/api/import/jumpstart', $requests[0]['url']);
	}

	public function testABarePushStillSendsExactlyOneMirrorRequest(): void
	{
		// The full mirror is the contract every existing install relies on:
		// one request, one endpoint, upsert semantics. Splitting must never
		// reach a push that names no --objects.
		$payload = new JumpStartData('Local', '');
		$payload->addSchema(['id' => 'products', 'properties' => []]);
		$payload->addObject(['collection' => 'builder-pages', 'id' => 'home']);
		$this->exporter->method('exportSyncData')->willReturn($payload);

		$requests = [];
		$this->recordingClient($requests);

		$result = $this->service->push('https://prod.example.com', 'k');

		$this->assertCount(1, $requests);
		$this->assertSame('https://prod.example.com/api/sync/import', $requests[0]['url']);
		expect($result->success)->toBeTrue();
	}

	public function testASplitPushReportsTheSeedLegFailingAfterTheMirrorLanded(): void
	{
		// Partial success is the split's own failure mode: the mirror is
		// already on the target when the seed is refused. Swallowing that
		// would leave the operator believing the whole push landed.
		$payload = new JumpStartData('Local', '');
		$payload->addSchema(['id' => 'faq', 'properties' => []]);
		$payload->addObject(['collection' => 'faq', 'id' => 'what-is-t3']);
		$this->exporter->method('exportSyncData')->willReturn($payload);

		$requests = [];
		$this->recordingClient($requests, [
			new HttpResponse(200, (string)json_encode(['success' => true])),
			new HttpResponse(200, (string)json_encode([
				'success' => false,
				'errors'  => ['Object what-is-t3: collection faq does not exist'],
			])),
		]);

		$result = $this->service->push('https://prod.example.com', 'k', ['faq'], [], [], [], ['faq' => null], false);

		$this->assertCount(2, $requests);
		expect($result->success)->toBeFalse();
		expect($result->message)->toContain('mirror payload landed');
		expect($result->message)->toContain('seeded objects failed');
		expect($result->error)->toContain('Seed: Object what-is-t3');
		expect($result->data['schemas'])->toBe(1);
		expect($result->data['seeded'])->toBe(1);
	}

	public function testASplitPushHoldsBackTheSeedWhenTheMirrorFails(): void
	{
		// Mirror first, and only then the seed: rows must not land in a
		// collection whose schema or settings never arrived.
		$payload = new JumpStartData('Local', '');
		$payload->addSchema(['id' => 'faq', 'properties' => []]);
		$payload->addObject(['collection' => 'faq', 'id' => 'what-is-t3']);
		$this->exporter->method('exportSyncData')->willReturn($payload);

		$requests = [];
		$this->recordingClient($requests, [
			new HttpResponse(500, (string)json_encode(['error' => ['message' => 'Boom']])),
		]);

		$result = $this->service->push('https://prod.example.com', 'k', ['faq'], [], [], [], ['faq' => null], false);

		$this->assertCount(1, $requests);
		$this->assertSame('https://prod.example.com/api/sync/import', $requests[0]['url']);
		expect($result->success)->toBeFalse();
		expect($result->message)->toContain('mirror payload failed');
		expect($result->message)->toContain('not sent');
		expect($result->error)->toContain('Mirror: Push failed (HTTP 500): Boom');
	}

	public function testPullReportsObjectsAndCollectionsSeparately(): void
	{
		// Objects used to be reported under the `collections` key, which made a
		// settings-only sync read as 0 and an object sync read as if it had
		// moved collections. They are distinct counts.
		$remotePayload = json_encode([
			'schemas'     => [],
			'templates'   => [],
			'objects'     => [
				['collection' => 'builder-pages', 'id' => 'home', 'data' => []],
				['collection' => 'builder-pages', 'id' => 'about', 'data' => []],
			],
			'collections' => ['reserved' => [], 'custom' => []],
		]);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)$remotePayload));

		$this->importer->method('importFromDefinition')
			->willReturn(OperationResult::success('Import complete.', ['results' => [], 'errors' => [], 'summary' => []]));

		$result = $this->service->pull('https://example.com', 'key');

		expect($result->success)->toBeTrue();
		expect($result->data['objects'])->toBe(2);
		expect($result->data['collections'])->toBe(0);
	}

	public function testPullImportsAPayloadThatOnlyCarriesCollectionSettings(): void
	{
		// The "nothing to pull" guard used to check schemas, templates and
		// objects only, so a settings-only pull short-circuited and never
		// reached the importer — the collection silently never arrived.
		$remotePayload = json_encode([
			'schemas'     => [],
			'templates'   => [],
			'objects'     => [],
			'collections' => [
				'reserved' => [],
				'custom'   => [['id' => 'addons', 'schema' => 'extension', 'name' => 'Extensions']],
			],
		]);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)$remotePayload));

		$this->importer->expects($this->once())
			->method('importFromDefinition')
			->willReturn(OperationResult::success('Import complete.', ['results' => [], 'errors' => [], 'summary' => []]));

		$result = $this->service->pull('https://example.com', 'key');

		expect($result->success)->toBeTrue();
		expect($result->message)->not->toContain('Nothing to pull');
		expect($result->data['collections'])->toBe(1);
	}

	public function testPullReportsFailureWhenTheImportCollectsErrors(): void
	{
		$remotePayload = json_encode([
			'schemas'   => [],
			'templates' => [],
			'objects'   => [['collection' => 'builder-pages', 'id' => 'home', 'data' => []]],
		]);

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string)$remotePayload));

		$this->importer->method('importFromDefinition')->willReturn(
			OperationResult::success('Import completed with errors', [
				'results' => [],
				'errors'  => ['Collection extensions: Cannot save collection with a reserved name'],
				'summary' => [],
			]),
		);

		$result = $this->service->pull('https://example.com', 'key');

		expect($result->success)->toBeFalse();
		expect($result->error)->toContain('reserved name');
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
