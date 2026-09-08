<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\Utils;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\Utils\AccessPageData;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData as AccessGroup;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Schema\Service\SchemaLister;

final class AccessPageDataTest extends TestCase
{
	private MockObject $apiKeyFetcher;
	private MockObject $accessGroupLister;
	private MockObject $collectionLister;
	private MockObject $schemaLister;
	private MockObject $extensionManager;
	private AccessPageData $builder;

	protected function setUp(): void
	{
		$this->apiKeyFetcher     = $this->createMock(ApiKeyFetcher::class);
		$this->accessGroupLister = $this->createMock(AccessGroupLister::class);
		$this->collectionLister  = $this->createMock(CollectionLister::class);
		$this->schemaLister      = $this->createMock(SchemaLister::class);
		$this->extensionManager  = $this->createMock(ExtensionManager::class);
		$this->builder           = new AccessPageData(
			$this->apiKeyFetcher,
			$this->accessGroupLister,
			$this->collectionLister,
			$this->schemaLister,
			$this->extensionManager,
		);
	}

	public function testApiKeysPageListsKeysExceptOnTheNewForm(): void
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$this->apiKeyFetcher->method('getAllKeys')->willReturn([['id' => 'k1']]);

		$this->assertSame([['id' => 'k1']], $this->builder->build($request, 'api-keys', '')['apiKeys']);
		$this->assertNull($this->builder->build($request, 'api-keys', 'new')['apiKeys']);
		$this->assertNull($this->builder->build($request, 'api-keys', '')['accessGroupsData']);
	}

	public function testAccessGroupsPageEnsuresTheDefaultGroupAndListsEverything(): void
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$this->accessGroupLister->expects($this->once())->method('ensureDefaultGroupExists');
		$this->accessGroupLister->method('listAll')->willReturn(['g']);
		$this->collectionLister->method('listAllCollections')->willReturn(['c']);
		$this->schemaLister->method('listAllSchemas')->willReturn(['s']);
		$this->extensionManager->method('listExtensionsWithAdminSurface')->willReturn(['e']);

		$data = $this->builder->build($request, 'access-groups', '')['accessGroupsData'];

		$this->assertSame(['g'], $data['groups']);
		$this->assertSame(['c'], $data['collections']);
		$this->assertSame(['s'], $data['schemas']);
		$this->assertSame(['e'], $data['extensions']);
		$this->assertSame('', $data['group']);
		$this->assertFalse($data['isEdit']);
	}

	public function testAccessGroupsEditActionLoadsTheGroup(): void
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$groupData = new AccessGroup(['id' => 'editors']);
		$this->accessGroupLister->method('findById')->with('editors')->willReturn($groupData);

		$data = $this->builder->build($request, 'access-groups', 'editors')['accessGroupsData'];

		$this->assertSame('editors', $data['group']->id);
		$this->assertTrue($data['isEdit']);
	}

	public function testAccessGroupsNewActionIsNotAnEdit(): void
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$this->accessGroupLister->expects($this->never())->method('findById');

		$data = $this->builder->build($request, 'access-groups', 'new')['accessGroupsData'];

		$this->assertFalse($data['isEdit']);
	}
}
