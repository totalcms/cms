<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderOrderService;
use TotalCMS\Domain\Builder\Service\BuilderReorderService;

final class BuilderReorderServiceTest extends TestCase
{
	private BuilderConfigService&MockObject $config;
	private BuilderOrderService&MockObject $order;
	private BuilderReorderService $service;

	protected function setUp(): void
	{
		$this->config  = $this->createMock(BuilderConfigService::class);
		$this->order   = $this->createMock(BuilderOrderService::class);
		$this->service = new BuilderReorderService($this->config, $this->order);

		$this->config->method('getPagesCollectionId')->willReturn('builder-pages');
	}

	public function testHappyPathWritesAndReturnsCount(): void
	{
		$tree = [
			['id' => 'home', 'children' => []],
			['id' => 'blog', 'children' => [
				['id' => 'post', 'children' => []],
			]],
		];

		$this->order->expects($this->once())
			->method('write')
			->with('builder-pages', $tree)
			->willReturn($tree);

		$result = $this->service->applyTree(['tree' => json_encode($tree)]);

		$this->assertTrue($result['ok']);
		$this->assertSame(3, $result['count']);
	}

	public function testCountsNodesRecursively(): void
	{
		$tree = [
			['id' => 'a', 'children' => [
				['id' => 'b', 'children' => [
					['id' => 'c', 'children' => []],
				]],
			]],
			['id' => 'd', 'children' => []],
		];

		$this->order->method('write')->willReturn($tree);

		$result = $this->service->applyTree(['tree' => json_encode($tree)]);

		$this->assertSame(4, $result['count']);
	}

	public function testReturnsErrorWhenTreeKeyMissing(): void
	{
		$this->order->expects($this->never())->method('write');

		$result = $this->service->applyTree([]);

		$this->assertFalse($result['ok']);
		$this->assertSame('Missing or invalid tree', $result['error']);
	}

	public function testReturnsErrorWhenTreeIsNotAString(): void
	{
		$result = $this->service->applyTree(['tree' => ['not', 'a', 'string']]);

		$this->assertFalse($result['ok']);
		$this->assertSame('Missing or invalid tree', $result['error']);
	}

	public function testReturnsErrorWhenTreeIsEmptyString(): void
	{
		$result = $this->service->applyTree(['tree' => '']);

		$this->assertFalse($result['ok']);
		$this->assertSame('Missing or invalid tree', $result['error']);
	}

	public function testReturnsErrorWhenJsonIsMalformed(): void
	{
		$result = $this->service->applyTree(['tree' => 'not json {']);

		$this->assertFalse($result['ok']);
		$this->assertSame('Missing or invalid tree', $result['error']);
	}

	public function testReturnsErrorWhenJsonDecodesToScalar(): void
	{
		$result = $this->service->applyTree(['tree' => '"a string"']);

		$this->assertFalse($result['ok']);
		$this->assertSame('Missing or invalid tree', $result['error']);
	}

	public function testFiltersOutNonArrayNodesBeforeWriting(): void
	{
		// Mixed-type top-level array — non-array entries get dropped before
		// being handed to BuilderOrderService.
		$payloadTree = [
			['id' => 'home', 'children' => []],
			'not-an-object',
			42,
			['id' => 'about', 'children' => []],
		];

		$this->order->expects($this->once())
			->method('write')
			->with(
				'builder-pages',
				$this->callback(function (array $tree): bool {
					return count($tree) === 2
						&& $tree[0]['id'] === 'home'
						&& $tree[1]['id'] === 'about';
				}),
			)
			->willReturnArgument(1);

		$result = $this->service->applyTree(['tree' => json_encode($payloadTree)]);

		$this->assertTrue($result['ok']);
	}

	public function testWriteThrowingSurfacesAsReorderFailedError(): void
	{
		$tree = [['id' => 'home', 'children' => []]];

		$this->order->method('write')->willThrowException(new \RuntimeException('disk full'));

		$result = $this->service->applyTree(['tree' => json_encode($tree)]);

		$this->assertFalse($result['ok']);
		$this->assertStringStartsWith('Reorder failed: ', $result['error']);
		$this->assertStringContainsString('disk full', $result['error']);
	}

	public function testEmptyTreePayloadIsAcceptedAsValid(): void
	{
		// `[]` is a perfectly valid reorder — means "no pages."
		$this->order->expects($this->once())
			->method('write')
			->with('builder-pages', [])
			->willReturn([]);

		$result = $this->service->applyTree(['tree' => '[]']);

		$this->assertTrue($result['ok']);
		$this->assertSame(0, $result['count']);
	}

	public function testNonArrayChildrenInCountAreIgnored(): void
	{
		// Defensive: countNodes guards against `children` not being an array.
		$tree = [
			['id' => 'home', 'children' => 'not-an-array'],
			['id' => 'about', 'children' => []],
		];

		$this->order->method('write')->willReturn($tree);

		$result = $this->service->applyTree(['tree' => json_encode($tree)]);

		$this->assertSame(2, $result['count']);
	}
}
