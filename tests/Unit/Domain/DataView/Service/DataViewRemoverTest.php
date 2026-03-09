<?php

namespace Tests\Unit\Domain\DataView\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\DataView\Repository\DataViewRepository;
use TotalCMS\Domain\DataView\Service\DataViewRemover;

final class DataViewRemoverTest extends TestCase
{
	private DataViewRemover $remover;
	private MockObject&DataViewRepository $repository;

	protected function setUp(): void
	{
		$this->repository = $this->createMock(DataViewRepository::class);
		$this->remover    = new DataViewRemover($this->repository);
	}

	public function testDeleteComputedDataDelegatesToRepository(): void
	{
		$this->repository->expects($this->once())
			->method('deleteData')
			->with('view-to-remove');

		$this->remover->deleteComputedData('view-to-remove');
	}
}
