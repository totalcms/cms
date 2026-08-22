<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupManager;
use TotalCMS\Domain\Auth\Service\FirstLoginChecker;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;

final class FirstLoginCheckerTest extends TestCase
{
	private FirstLoginChecker $checker;
	private \PHPUnit\Framework\MockObject\MockObject $objectSaver;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $indexReader;
	private \PHPUnit\Framework\MockObject\MockObject $accessGroupManager;
	private Config $config;

	protected function setUp(): void
	{
		$this->objectSaver        = $this->createMock(ObjectSaver::class);
		$this->collectionFetcher  = $this->createMock(CollectionFetcher::class);
		$this->indexReader        = $this->createMock(IndexReader::class);
		$this->accessGroupManager = $this->createMock(AccessGroupManager::class);

		// Config is a plain data class; instantiate without ctor and set the
		// `auth.collection` value the checker reads in its constructor.
		$this->config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->auth = ['collection' => 'admin'];

		$this->checker = new FirstLoginChecker(
			$this->objectSaver,
			$this->collectionFetcher,
			$this->indexReader,
			$this->config,
			$this->accessGroupManager,
		);
	}

	public function testCreateFirstUserSavesTheSuppliedName(): void
	{
		$captured = [];
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function (string $collection, array $data) use (&$captured) {
				$captured = $data;

				return $this->createMock(\TotalCMS\Domain\Object\Data\ObjectData::class);
			});

		$this->checker->createFirstUser('a@b.com', 'password123', 'Joe Workman');

		$this->assertSame('Joe Workman', $captured['name'] ?? null);
		$this->assertSame('a@b.com', $captured['email'] ?? null);
	}

	public function testCreateFirstUserDefaultsNameToAdminWhenOmitted(): void
	{
		$captured = [];
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function (string $collection, array $data) use (&$captured) {
				$captured = $data;

				return $this->createMock(\TotalCMS\Domain\Object\Data\ObjectData::class);
			});

		$this->checker->createFirstUser('a@b.com', 'password123');

		$this->assertSame('Admin', $captured['name'] ?? null);
	}

	public function testCreateFirstUserFallsBackToAdminWhenNameIsBlank(): void
	{
		$captured = [];
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function (string $collection, array $data) use (&$captured) {
				$captured = $data;

				return $this->createMock(\TotalCMS\Domain\Object\Data\ObjectData::class);
			});

		$this->checker->createFirstUser('a@b.com', 'password123', '   ');

		$this->assertSame('Admin', $captured['name'] ?? null);
	}
}
