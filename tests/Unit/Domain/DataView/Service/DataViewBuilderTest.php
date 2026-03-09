<?php

namespace Tests\Unit\Domain\DataView\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\DataView\Data\DataViewData;
use TotalCMS\Domain\DataView\Repository\DataViewRepository;
use TotalCMS\Domain\DataView\Service\DataViewBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;

final class DataViewBuilderTest extends TestCase
{
	private DataViewBuilder $builder;
	private MockObject&DataViewRepository $repository;
	private MockObject&ObjectFetcher $objectFetcher;
	private MockObject&ObjectUpdater $objectUpdater;
	private MockObject&TwigEngine $twigEngine;

	protected function setUp(): void
	{
		$this->repository    = $this->createMock(DataViewRepository::class);
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->objectUpdater = $this->createMock(ObjectUpdater::class);
		$this->twigEngine    = $this->createMock(TwigEngine::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$this->builder = new DataViewBuilder(
			$this->repository,
			$this->objectFetcher,
			$this->objectUpdater,
			$this->twigEngine,
			$loggerFactory,
		);
	}

	/** @param array<string,mixed> $arrayData */
	private function createMockObjectData(string $id, array $arrayData): ObjectData
	{
		$object = $this->createMock(ObjectData::class);
		$object->method('toArray')->willReturn(array_merge(['id' => $id], $arrayData));

		return $object;
	}

	public function testBuildViewFetchesObjectExecutesDefinitionAndSavesData(): void
	{
		$object = $this->createMockObjectData('test-view', [
			'definition' => '{% set data = {"items": [1,2]} %}',
		]);

		$this->objectFetcher->expects($this->once())
			->method('fetchObject')
			->with(DataViewData::COLLECTION_ID, 'test-view')
			->willReturn($object);

		$this->twigEngine->expects($this->once())
			->method('renderString')
			->willReturn('{"items":[1,2]}');

		$this->repository->expects($this->once())
			->method('saveData')
			->with('test-view', ['items' => [1, 2]]);

		$this->objectUpdater->expects($this->once())
			->method('updateObject')
			->with(
				DataViewData::COLLECTION_ID,
				'test-view',
				$this->callback(fn (array $data): bool => isset($data['lastBuilt']) && $data['lastError'] === '')
			);

		$this->builder->buildView('test-view');
	}

	public function testBuildViewLogsErrorWhenDefinitionIsEmpty(): void
	{
		$object = $this->createMockObjectData('empty-view', [
			'definition' => '',
		]);

		$this->objectFetcher->method('fetchObject')
			->willReturn($object);

		$this->repository->expects($this->never())
			->method('saveData');

		$this->objectUpdater->expects($this->once())
			->method('updateObject')
			->with(
				DataViewData::COLLECTION_ID,
				'empty-view',
				$this->callback(fn (array $data): bool => $data['lastError'] === 'View definition is empty')
			);

		$this->builder->buildView('empty-view');
	}

	public function testBuildViewLogsErrorOnTwigFailure(): void
	{
		$object = $this->createMockObjectData('broken-view', [
			'definition' => '{% set data = broken %}',
		]);

		$this->objectFetcher->method('fetchObject')
			->willReturn($object);

		$this->twigEngine->method('renderString')
			->willThrowException(new \RuntimeException('Twig render error'));

		$this->repository->expects($this->never())
			->method('saveData');

		$this->objectUpdater->expects($this->once())
			->method('updateObject')
			->with(
				DataViewData::COLLECTION_ID,
				'broken-view',
				$this->callback(fn (array $data): bool => str_contains((string)$data['lastError'], 'Twig render error'))
			);

		$this->builder->buildView('broken-view');
	}

	public function testTestViewReturnsSuccessResult(): void
	{
		$this->twigEngine->expects($this->once())
			->method('renderString')
			->willReturn('{"result":"ok"}');

		$result = $this->builder->testView('{% set data = {"result": "ok"} %}');

		$this->assertTrue($result['success']);
		$this->assertSame(['result' => 'ok'], $result['data']);
		$this->assertNull($result['error']);
	}

	public function testTestViewReturnsErrorResultOnFailure(): void
	{
		$this->twigEngine->method('renderString')
			->willThrowException(new \RuntimeException('Template syntax error'));

		$result = $this->builder->testView('{% invalid %}');

		$this->assertFalse($result['success']);
		$this->assertNull($result['data']);
		$this->assertSame('Template syntax error', $result['error']);
	}
}
