<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\Utils;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\Utils\VisualizerPageData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Visualizer\Service\VisualizerService;

final class VisualizerPageDataTest extends TestCase
{
	private MockObject $visualizer;
	private MockObject $collectionLister;
	private MockObject $session;
	private MockObject $request;
	private VisualizerPageData $builder;

	protected function setUp(): void
	{
		$this->visualizer       = $this->createMock(VisualizerService::class);
		$this->collectionLister = $this->createMock(CollectionLister::class);
		$this->session          = $this->createMock(SessionInterface::class);
		$this->request          = $this->createMock(ServerRequestInterface::class);
		$this->collectionLister->method('listAllCollections')->willReturn(['c']);
		$this->builder = new VisualizerPageData($this->visualizer, $this->collectionLister, $this->session);
	}

	public function testCollectionVisualizerPassesCollectionsQueryAndCurrentUser(): void
	{
		$this->request->method('getQueryParams')->willReturn(['focus' => 'blog']);
		$this->session->method('get')->with(SessionKeys::AUTH_USER)->willReturn('joe');
		$this->visualizer->expects($this->once())->method('collectionGraph')
			->with(['c'], ['focus' => 'blog'], 'joe')->willReturn(['graph' => 1]);

		$data = $this->builder->build($this->request, 'collection-visualizer', '');

		$this->assertSame(['graph' => 1], $data['visualizerData']);
		$this->assertNull($data['objectVisualizerData']);
		$this->assertNull($data['permissionMatrixData']);
	}

	public function testObjectVisualizerPassesNullUserWhenNobodyIsLoggedIn(): void
	{
		$this->request->method('getQueryParams')->willReturn([]);
		$this->session->method('get')->willReturn(null);
		$this->visualizer->expects($this->once())->method('objectGraph')
			->with(['c'], [], null)->willReturn(['graph' => 2]);

		$data = $this->builder->build($this->request, 'object-visualizer', '');

		$this->assertSame(['graph' => 2], $data['objectVisualizerData']);
	}

	public function testPermissionMatrixTrimsTheGroupFilter(): void
	{
		$this->request->method('getQueryParams')->willReturn(['group' => ' editors ']);
		$this->visualizer->method('accessGroupMatrix')->willReturn(['m']);

		$data = $this->builder->build($this->request, 'permission-matrix', '');

		$this->assertSame(['matrix' => ['m'], 'group' => 'editors'], $data['permissionMatrixData']);
	}
}
