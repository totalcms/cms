<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\Utils;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\Utils\JumpStartPageData;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Schema\Service\SchemaLister;

final class JumpStartPageDataTest extends TestCase
{
	public function testListsCustomSchemasAndAllCollections(): void
	{
		$schemas     = $this->createMock(SchemaLister::class);
		$collections = $this->createMock(CollectionLister::class);
		$schemas->method('listCustomSchemas')->willReturn(['custom']);
		$collections->method('listAllCollections')->willReturn([$this->collection('blog', 'Blog')]);

		$data = (new JumpStartPageData($schemas, $collections))
			->build($this->createMock(ServerRequestInterface::class), 'jumpstart', '');

		$this->assertSame(['custom'], $data['jumpstartData']['schemas']);
		$this->assertSame(['blog'], array_map(static fn (CollectionData $c): string => $c->id, $data['jumpstartData']['collections']));
	}

	public function testCollectionsAreSortedByDisplayedNameNotId(): void
	{
		$schemas     = $this->createMock(SchemaLister::class);
		$collections = $this->createMock(CollectionLister::class);
		$schemas->method('listCustomSchemas')->willReturn([]);
		// The lister returns id order; the picker shows names, so it must
		// not come out as [Zebra, Apples, mid].
		$collections->method('listAllCollections')->willReturn([
			$this->collection('a-first', 'Zebra'),
			$this->collection('b-second', 'Apples'),
			$this->collection('c-third', 'mid'),
		]);

		$data = (new JumpStartPageData($schemas, $collections))
			->build($this->createMock(ServerRequestInterface::class), 'jumpstart', '');

		$this->assertSame(
			['Apples', 'mid', 'Zebra'],
			array_map(static fn (CollectionData $c): string => $c->name, $data['jumpstartData']['collections']),
		);
	}

	private function collection(string $id, string $name): CollectionData
	{
		$collection       = (new \ReflectionClass(CollectionData::class))->newInstanceWithoutConstructor();
		$collection->id   = $id;
		$collection->name = $name;

		return $collection;
	}
}
