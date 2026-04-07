<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Sync;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Sync\Service\SyncService;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

final class SyncServiceTest extends TestCase
{
	private SyncService $service;
	private \PHPUnit\Framework\MockObject\MockObject $exporter;
	private \PHPUnit\Framework\MockObject\MockObject $importer;
	private \PHPUnit\Framework\MockObject\MockObject $httpClient;

	protected function setUp(): void
	{
		$this->exporter   = $this->createMock(JumpStartExporter::class);
		$this->importer   = $this->createMock(JumpStartImporter::class);
		$this->httpClient = $this->createMock(HttpClientInterface::class);

		$this->service = new SyncService(
			$this->exporter,
			$this->importer,
			$this->httpClient,
		);
	}

	// ==================== Push Tests ====================

	public function testPushReturnsNothingWhenEmpty(): void
	{
		$emptyData = new JumpStartData();

		$this->exporter->method('exportSyncData')->willReturn($emptyData);
		$this->exporter->method('setMetadata');

		$result = $this->service->push('https://example.com', 'key');

		expect($result['success'])->toBeTrue();
		expect($result['schemas'])->toBe(0);
		expect($result['templates'])->toBe(0);
		expect($result['message'])->toContain('Nothing to push');
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
				'https://example.com/import/jumpstart',
				$this->callback(function (array $options): bool {
					expect($options['body'])->toContain('products');
					expect($options['headers'][0])->toContain('Bearer test-key');
					return true;
				})
			)
			->willReturn(new HttpResponse(200, json_encode([
				'success' => true,
				'summary' => ['schemas_created' => 1, 'templates_created' => 1],
			])));

		$result = $this->service->push('https://example.com', 'test-key');

		expect($result['success'])->toBeTrue();
		expect($result['message'])->toBe('Push complete.');
		expect($result['schemas'])->toBe(1);
		expect($result['templates'])->toBe(1);
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

		$this->httpClient->expects($this->once())
			->method('request')
			->with('GET', 'https://example.com/export/jumpstart?mode=sync', $this->anything())
			->willReturn(new HttpResponse(200, (string) $remotePayload));

		$this->importer->expects($this->once())
			->method('importFromDefinition')
			->willReturn([
				'success' => true,
				'results' => [],
				'errors'  => [],
				'summary' => ['schemas_created' => 1, 'templates_created' => 1],
			]);

		$result = $this->service->pull('https://example.com', 'key');

		expect($result['success'])->toBeTrue();
		expect($result['message'])->toBe('Pull complete.');
		expect($result['schemas'])->toBe(1);
		expect($result['templates'])->toBe(1);
	}

	public function testPullReturnsNothingWhenRemoteEmpty(): void
	{
		$this->httpClient->method('request')->willReturn(new HttpResponse(200, json_encode([
			'schemas'   => [],
			'templates' => [],
		])));

		$this->importer->expects($this->never())->method('importFromDefinition');

		$result = $this->service->pull('https://example.com', 'key');

		expect($result['success'])->toBeTrue();
		expect($result['schemas'])->toBe(0);
		expect($result['templates'])->toBe(0);
		expect($result['message'])->toContain('Nothing to pull');
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

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string) $remotePayload));

		$this->importer->expects($this->once())
			->method('importFromDefinition')
			->with($this->callback(function (array $payload): bool {
				expect($payload['schemas'])->toHaveCount(1);
				expect($payload['schemas'][0]['id'])->toBe('products');
				return true;
			}))
			->willReturn(['success' => true, 'results' => [], 'errors' => [], 'summary' => []]);

		$this->service->pull('https://example.com', 'key', ['products']);
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

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string) $remotePayload));

		$this->importer->expects($this->once())
			->method('importFromDefinition')
			->with($this->callback(function (array $payload): bool {
				expect($payload['templates'])->toHaveCount(1);
				expect($payload['templates'][0]['id'])->toBe('sidebar');
				return true;
			}))
			->willReturn(['success' => true, 'results' => [], 'errors' => [], 'summary' => []]);

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

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string) $remotePayload));

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

		$this->httpClient->method('request')->willReturn(new HttpResponse(200, (string) $remotePayload));

		$result = $this->service->fetchRemoteSyncData('https://example.com', 'key');

		expect($result['schemas'])->toHaveCount(2);
		expect($result['templates'])->toHaveCount(1);
	}
}
