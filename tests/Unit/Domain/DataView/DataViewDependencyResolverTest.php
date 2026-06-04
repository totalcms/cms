<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\DataView;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\DataView\Service\DataViewDependencyResolver;

final class DataViewDependencyResolverTest extends TestCase
{
	/** @param array<int,array<string,mixed>> $views */
	private function order(array $views, string $collection): array
	{
		$resolver = (new \ReflectionClass(DataViewDependencyResolver::class))->newInstanceWithoutConstructor();
		$prop = new \ReflectionProperty(DataViewDependencyResolver::class, 'logger');
		$prop->setValue($resolver, new NullLogger());

		return $resolver->order($views, $collection);
	}

	public function testProducerBeforeConsumer(): void
	{
		$views = [
			['id' => 'B', 'dependencies' => ['orders'], 'viewDependencies' => ['A']],
			['id' => 'A', 'dependencies' => ['orders'], 'viewDependencies' => []],
		];
		$this->assertSame(['A', 'B'], $this->order($views, 'orders'));
	}

	public function testTransitiveCollectionInheritance(): void
	{
		$views = [
			['id' => 'A', 'dependencies' => ['collA', 'collB'], 'viewDependencies' => []],
			['id' => 'B', 'dependencies' => ['collX'], 'viewDependencies' => ['A']],
		];
		$this->assertSame(['A', 'B'], $this->order($views, 'collA'));
	}

	public function testUnrelatedCollectionRebuildsOnlyDirect(): void
	{
		$views = [
			['id' => 'A', 'dependencies' => ['collA'], 'viewDependencies' => []],
			['id' => 'B', 'dependencies' => ['collX'], 'viewDependencies' => ['A']],
		];
		$this->assertSame(['B'], $this->order($views, 'collX'));
	}

	public function testDiamondOrdersBothProducersBeforeConsumer(): void
	{
		$views = [
			['id' => 'C', 'dependencies' => [], 'viewDependencies' => ['A', 'B']],
			['id' => 'A', 'dependencies' => ['orders'], 'viewDependencies' => []],
			['id' => 'B', 'dependencies' => ['orders'], 'viewDependencies' => []],
		];
		$result = $this->order($views, 'orders');
		$this->assertContains('A', $result);
		$this->assertContains('B', $result);
		$this->assertGreaterThan(array_search('A', $result, true), array_search('C', $result, true));
		$this->assertGreaterThan(array_search('B', $result, true), array_search('C', $result, true));
	}

	public function testCycleIsBrokenNotInfinite(): void
	{
		$views = [
			['id' => 'A', 'dependencies' => ['orders'], 'viewDependencies' => ['B']],
			['id' => 'B', 'dependencies' => ['orders'], 'viewDependencies' => ['A']],
		];
		$result = $this->order($views, 'orders');
		sort($result);
		$this->assertSame(['A', 'B'], $result);
	}

	public function testNoMatchReturnsEmpty(): void
	{
		$views = [['id' => 'A', 'dependencies' => ['collA'], 'viewDependencies' => []]];
		$this->assertSame([], $this->order($views, 'nope'));
	}

	public function testThreeLevelViewChain(): void
	{
		// Only A is direct; B and C are pulled in transitively (A -> B -> C).
		$views = [
			['id' => 'C', 'dependencies' => [], 'viewDependencies' => ['B']],
			['id' => 'B', 'dependencies' => [], 'viewDependencies' => ['A']],
			['id' => 'A', 'dependencies' => ['orders'], 'viewDependencies' => []],
		];
		$this->assertSame(['A', 'B', 'C'], $this->order($views, 'orders'));
	}

	public function testSelfReferenceDoesNotHang(): void
	{
		// A self-referencing viewDependency is a degenerate cycle: must not hang.
		$views = [
			['id' => 'A', 'dependencies' => ['orders'], 'viewDependencies' => ['A']],
		];
		$this->assertSame(['A'], $this->order($views, 'orders'));
	}
}
