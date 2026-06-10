<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Compat;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Tool\Compat\CompositeObjectId;

final class CompositeObjectIdTest extends TestCase
{
	public function testEncodeJoinsWithColon(): void
	{
		$this->assertSame('blog:hello-world', CompositeObjectId::encode('blog', 'hello-world'));
	}

	public function testSplitReturnsCollectionAndId(): void
	{
		$this->assertSame(['blog', 'hello-world'], CompositeObjectId::split('blog:hello-world'));
	}

	public function testSplitUsesFirstColonOnly(): void
	{
		$this->assertSame(['blog', 'a:b'], CompositeObjectId::split('blog:a:b'));
	}

	public function testSplitReturnsNullForMalformedInput(): void
	{
		$this->assertNull(CompositeObjectId::split('no-colon'));
		$this->assertNull(CompositeObjectId::split(':missing-collection'));
		$this->assertNull(CompositeObjectId::split('missing-id:'));
		$this->assertNull(CompositeObjectId::split(''));
	}

	public function testEncodeSplitRoundTrip(): void
	{
		$this->assertSame(
			['blog', 'hello-world'],
			CompositeObjectId::split(CompositeObjectId::encode('blog', 'hello-world')),
		);
	}
}
