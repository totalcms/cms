<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupManager;
use TotalCMS\Domain\Auth\Service\FirstLoginChecker;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;

final class FirstLoginCheckerTest extends TestCase
{
	private FirstLoginChecker $checker;
	private MockObject $objectSaver;
	private MockObject $collectionFetcher;
	private MockObject $indexReader;
	private MockObject $accessGroupManager;
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

				return $this->createMock(ObjectData::class);
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

				return $this->createMock(ObjectData::class);
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

				return $this->createMock(ObjectData::class);
			});

		$this->checker->createFirstUser('a@b.com', 'password123', '   ');

		$this->assertSame('Admin', $captured['name'] ?? null);
	}

	public function testCreateFirstUserDoesNotHardcodeTheId(): void
	{
		// The auth schema's id field has `settings.autogen: "${oid}"` — the
		// saved payload must omit 'id' entirely so ObjectFactory/ObjectSaver
		// generate it, instead of forcing the literal id 'admin'.
		$captured = [];
		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function (string $collection, array $data) use (&$captured) {
				$captured = $data;

				return $this->createMock(ObjectData::class);
			});

		$this->checker->createFirstUser('a@b.com', 'password123', 'Joe Workman');

		$this->assertArrayNotHasKey('id', $captured);
	}
}
