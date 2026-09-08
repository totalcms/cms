<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\Utils;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\Utils\JumpStartPageData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Schema\Service\SchemaLister;

final class JumpStartPageDataTest extends TestCase
{
	public function testListsCustomSchemasAndAllCollections(): void
	{
		$schemas     = $this->createMock(SchemaLister::class);
		$collections = $this->createMock(CollectionLister::class);
		$schemas->method('listCustomSchemas')->willReturn(['custom']);
		$collections->method('listAllCollections')->willReturn(['blog']);

		$data = (new JumpStartPageData($schemas, $collections))
			->build($this->createMock(ServerRequestInterface::class), 'jumpstart', '');

		$this->assertSame(['schemas' => ['custom'], 'collections' => ['blog']], $data['jumpstartData']);
	}
}
