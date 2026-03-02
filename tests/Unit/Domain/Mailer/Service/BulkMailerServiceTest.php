<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mailer\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mailer\Data\MailerData;
use TotalCMS\Domain\Mailer\Service\BulkMailerService;
use TotalCMS\Domain\Mailer\Service\MailerFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;

final class BulkMailerServiceTest extends TestCase
{
	private BulkMailerService $service;
	private \PHPUnit\Framework\MockObject\MockObject $mailerFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $indexFilter;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $jobQueuer;
	private \PHPUnit\Framework\MockObject\MockObject $editionFeatures;
	private \PHPUnit\Framework\MockObject\MockObject $twigEngine;
	private \PHPUnit\Framework\MockObject\MockObject $logger;

	protected function setUp(): void
	{
		$this->mailerFetcher   = $this->createMock(MailerFetcher::class);
		$this->indexFilter     = $this->createMock(IndexFilter::class);
		$this->objectFetcher   = $this->createMock(ObjectFetcher::class);
		$this->jobQueuer       = $this->createMock(JobQueuer::class);
		$this->editionFeatures = $this->createMock(EditionFeatureService::class);
		$this->twigEngine      = $this->createMock(TwigEngine::class);
		$this->logger          = $this->createMock(LoggerInterface::class);

		// By default, allow bulk mailer actions
		$this->editionFeatures->method('can')->willReturn(true);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->logger);

		$this->service = new BulkMailerService(
			$this->mailerFetcher,
			$this->indexFilter,
			$this->objectFetcher,
			$this->jobQueuer,
			$this->editionFeatures,
			$this->twigEngine,
			$loggerFactory,
		);
	}

	// ── queueBulkSend tests ──

	public function testReturnsErrorWhenEditionCheckFails(): void
	{
		$this->editionFeatures = $this->createMock(EditionFeatureService::class);
		$this->editionFeatures->method('can')
			->with(EditionFeature::BULK_MAILER)
			->willReturn(false);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->logger);

		$service = new BulkMailerService(
			$this->mailerFetcher,
			$this->indexFilter,
			$this->objectFetcher,
			$this->jobQueuer,
			$this->editionFeatures,
			$this->twigEngine,
			$loggerFactory,
		);

		$result = $service->queueBulkSend('test-mailer');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Pro edition', $result['message']);
	}

	public function testReturnsErrorWhenMailerNotFound(): void
	{
		$this->mailerFetcher->method('fetchMailer')
			->willThrowException(new \Exception('Not found'));

		$result = $this->service->queueBulkSend('nonexistent');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not found', $result['message']);
	}

	public function testReturnsErrorWhenMailerIsInactive(): void
	{
		$mailer = $this->createMailerData(active: false);
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);

		$result = $this->service->queueBulkSend('test-mailer');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not active', $result['message']);
	}

	public function testReturnsErrorWhenBulkCollectionIsEmpty(): void
	{
		$mailer = $this->createMailerData(bulkCollection: '');
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);

		$result = $this->service->queueBulkSend('test-mailer');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not configured', $result['message']);
	}

	public function testReturnsErrorWhenNoMatchingObjectsFound(): void
	{
		$mailer = $this->createMailerData();
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([]);

		$result = $this->service->queueBulkSend('test-mailer');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('No matching objects', $result['message']);
	}

	public function testQueuesJobsForEachMatchingObject(): void
	{
		$mailer = $this->createMailerData();
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);

		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'obj-1'],
			['id' => 'obj-2'],
			['id' => 'obj-3'],
		]);

		$jobData = $this->createMock(JobData::class);
		$this->jobQueuer->expects($this->exactly(3))
			->method('queueEmail')
			->willReturn($jobData);

		$result = $this->service->queueBulkSend('test-mailer');

		$this->assertTrue($result['success']);
		$this->assertSame(3, $result['count']);
		$this->assertArrayHasKey('batchId', $result);
	}

	public function testPassesOverrideToThroughToJobData(): void
	{
		$mailer = $this->createMailerData();
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([['id' => 'obj-1']]);

		$jobData = $this->createMock(JobData::class);
		$this->jobQueuer->expects($this->once())
			->method('queueEmail')
			->with(
				$this->callback(fn (array $data): bool => $data['overrideTo'] === 'override@example.com'),
				$this->anything()
			)
			->willReturn($jobData);

		$this->service->queueBulkSend('test-mailer', null, 'override@example.com');
	}

	public function testPassesScheduledAtThroughToJobQueuer(): void
	{
		$mailer = $this->createMailerData();
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([['id' => 'obj-1']]);

		$jobData = $this->createMock(JobData::class);
		$this->jobQueuer->expects($this->once())
			->method('queueEmail')
			->with(
				$this->anything(),
				'2026-03-01 12:00:00'
			)
			->willReturn($jobData);

		$this->service->queueBulkSend('test-mailer', '2026-03-01 12:00:00');
	}

	public function testPassesIncludeExcludeFiltersToIndexFilter(): void
	{
		$mailer = $this->createMailerData(
			bulkInclude: 'status:active',
			bulkExclude: 'type:draft',
		);
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);

		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndex')
			->with(
				'subscribers',
				$this->callback(fn (array $options): bool => $options['include'] === 'status:active'
					&& $options['exclude'] === 'type:draft')
			)
			->willReturn([]);

		$this->service->queueBulkSend('test-mailer');
	}

	public function testSkipsObjectsWithEmptyIds(): void
	{
		$mailer = $this->createMailerData();
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'obj-1'],
			['id'   => ''],
			['name' => 'no-id'],
		]);

		$jobData = $this->createMock(JobData::class);
		$this->jobQueuer->expects($this->once())
			->method('queueEmail')
			->willReturn($jobData);

		$result = $this->service->queueBulkSend('test-mailer');

		$this->assertTrue($result['success']);
		$this->assertSame(1, $result['count']);
	}

	public function testReturnsCorrectSuccessStructure(): void
	{
		$mailer = $this->createMailerData();
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([['id' => 'obj-1']]);

		$jobData = $this->createMock(JobData::class);
		$this->jobQueuer->method('queueEmail')->willReturn($jobData);

		$result = $this->service->queueBulkSend('test-mailer');

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('batchId', $result);
		$this->assertStringStartsWith('bulk_', $result['batchId']);
		$this->assertSame(1, $result['count']);
		$this->assertStringContainsString('1 emails', $result['message']);
	}

	public function testUsesObjectIdsInsteadOfFiltersWhenProvided(): void
	{
		$mailer = $this->createMailerData(bulkInclude: 'status:active');
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);

		// IndexFilter should NOT be called when objectIds are provided
		$this->indexFilter->expects($this->never())->method('fetchFilteredIndex');

		$jobData = $this->createMock(JobData::class);
		$this->jobQueuer->expects($this->exactly(2))
			->method('queueEmail')
			->willReturn($jobData);

		$result = $this->service->queueBulkSend('test-mailer', null, null, ['obj-1', 'obj-2']);

		$this->assertTrue($result['success']);
		$this->assertSame(2, $result['count']);
	}

	public function testObjectIdsPassedAsJobData(): void
	{
		$mailer = $this->createMailerData();
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);

		$capturedIds = [];
		$jobData     = $this->createMock(JobData::class);
		$this->jobQueuer->method('queueEmail')
			->willReturnCallback(function (array $data) use (&$capturedIds, $jobData): JobData {
				$capturedIds[] = $data['objectId'];

				return $jobData;
			});

		$this->service->queueBulkSend('test-mailer', null, null, ['abc', 'def']);

		$this->assertSame(['abc', 'def'], $capturedIds);
	}

	public function testEmptyObjectIdsArrayFallsBackToFilters(): void
	{
		$mailer = $this->createMailerData();
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);

		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndex')
			->willReturn([['id' => 'obj-1']]);

		$jobData = $this->createMock(JobData::class);
		$this->jobQueuer->method('queueEmail')->willReturn($jobData);

		$result = $this->service->queueBulkSend('test-mailer', null, null, []);

		$this->assertTrue($result['success']);
		$this->assertSame(1, $result['count']);
	}

	public function testNullObjectIdsFallsBackToFilters(): void
	{
		$mailer = $this->createMailerData();
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);

		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndex')
			->willReturn([['id' => 'obj-1']]);

		$jobData = $this->createMock(JobData::class);
		$this->jobQueuer->method('queueEmail')->willReturn($jobData);

		$result = $this->service->queueBulkSend('test-mailer', null, null, null);

		$this->assertTrue($result['success']);
	}

	public function testEmptyOverrideToIsNormalisedToNull(): void
	{
		$mailer = $this->createMailerData();
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([['id' => 'obj-1']]);

		$jobData = $this->createMock(JobData::class);
		$this->jobQueuer->expects($this->once())
			->method('queueEmail')
			->with(
				$this->callback(fn (array $data): bool => $data['overrideTo'] === null),
				$this->anything()
			)
			->willReturn($jobData);

		$this->service->queueBulkSend('test-mailer', null, '');
	}

	// ── previewEmail tests ──

	public function testPreviewReturnsErrorWhenMailerNotFound(): void
	{
		$this->mailerFetcher->method('fetchMailer')
			->willThrowException(new \Exception('Not found'));

		$result = $this->service->previewEmail('nonexistent', 'obj-1', 'subscribers');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not found', $result['message']);
	}

	public function testPreviewReturnsRenderedHtml(): void
	{
		$mailer = $this->createMailerData(
			to: '{{ data.email }}',
			subject: 'Hello {{ data.name }}',
			bodyHtml: '<p>Welcome {{ data.name }}</p>',
		);
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);

		$objectData = new ObjectData('obj-1', [
			'name'  => 'John',
			'email' => 'john@example.com',
		]);
		$this->objectFetcher->method('fetchObject')
			->with('subscribers', 'obj-1')
			->willReturn($objectData);

		$this->twigEngine->method('renderString')
			->willReturnCallback(fn (string $template): string => match (true) {
				str_contains($template, '{{ data.email }}') => 'john@example.com',
				str_contains($template, '{{ data.name }}')  => str_replace('{{ data.name }}', 'John', $template),
				default                                     => $template,
			});

		$result = $this->service->previewEmail('test-mailer', 'obj-1', 'subscribers');

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('html', $result);
		$this->assertArrayHasKey('subject', $result);
		$this->assertArrayHasKey('to', $result);
		$this->assertSame('john@example.com', $result['to']);
	}

	public function testPreviewReturnsErrorOnObjectFetchFailure(): void
	{
		$mailer = $this->createMailerData();
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);

		$this->objectFetcher->method('fetchObject')
			->willThrowException(new \Exception('Object not found'));

		$result = $this->service->previewEmail('test-mailer', 'obj-1', 'subscribers');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Preview error', $result['message']);
	}

	public function testPreviewPassesObjectPropertiesAsTwigData(): void
	{
		$mailer = $this->createMailerData();
		$this->mailerFetcher->method('fetchMailer')->willReturn($mailer);

		$objectData = new ObjectData('obj-1', [
			'name'  => 'Jane',
			'email' => 'jane@example.com',
		]);
		$this->objectFetcher->method('fetchObject')->willReturn($objectData);

		$this->twigEngine->expects($this->atLeastOnce())
			->method('renderString')
			->with(
				$this->anything(),
				$this->callback(fn (array $twigData): bool => isset($twigData['data'])
					&& $twigData['data']['name'] === 'Jane'
					&& $twigData['data']['email'] === 'jane@example.com')
			)
			->willReturnArgument(0);

		$this->service->previewEmail('test-mailer', 'obj-1', 'subscribers');
	}

	// ── Helper ──

	private function createMailerData(
		bool $active = true,
		string $to = 'user@example.com',
		string $subject = 'Test Subject',
		string $bodyHtml = '<p>Test</p>',
		string $bulkCollection = 'subscribers',
		string $bulkInclude = '',
		string $bulkExclude = '',
	): MailerData {
		return new MailerData(
			id: 'test-mailer',
			active: $active,
			name: 'Test Bulk Mailer',
			description: 'Test description',
			from: 'noreply@example.com',
			fromName: 'System',
			to: $to,
			toName: 'User',
			replyTo: '',
			cc: '',
			bcc: '',
			subject: $subject,
			bodyHtml: $bodyHtml,
			bodyText: 'Test',
			bulkCollection: $bulkCollection,
			bulkInclude: $bulkInclude,
			bulkExclude: $bulkExclude,
		);
	}
}
