<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\ForcedValueContext;

// The request-scoped store of forced values the access middleware remembers
// for a non-admin write and ObjectUpdater::updateObject() applies.
final class ForcedValueContextTest extends TestCase
{
	public function testRememberedValuesAreReturned(): void
	{
		$context = new ForcedValueContext();
		$context->remember('blog', 'post-1', ['draft' => true]);

		$this->assertSame(['draft' => true], $context->forcedFor('blog', 'post-1'));
	}

	public function testAnUnknownPairReturnsNothing(): void
	{
		$this->assertSame([], (new ForcedValueContext())->forcedFor('blog', 'post-1'));
	}

	public function testRememberingNothingStoresNothing(): void
	{
		$context = new ForcedValueContext();
		$context->remember('blog', 'post-1', []);

		$this->assertSame([], $context->forcedFor('blog', 'post-1'));
	}

	public function testReadingDoesNotConsumeTheValues(): void
	{
		$context = new ForcedValueContext();
		$context->remember('blog', 'post-1', ['draft' => true]);

		$this->assertSame(['draft' => true], $context->forcedFor('blog', 'post-1'));
		$this->assertSame(['draft' => true], $context->forcedFor('blog', 'post-1'));
	}

	public function testIdsInOneCollectionAreIndependent(): void
	{
		$context = new ForcedValueContext();
		$context->remember('blog', 'post-1', ['draft' => true]);
		$context->remember('blog', 'post-2', ['featured' => false]);

		$this->assertSame(['draft' => true], $context->forcedFor('blog', 'post-1'));
		$this->assertSame(['featured' => false], $context->forcedFor('blog', 'post-2'));
		$this->assertSame([], $context->forcedFor('news', 'post-1'));
	}

	public function testClearEmptiesEverything(): void
	{
		$context = new ForcedValueContext();
		$context->remember('blog', 'post-1', ['draft' => true]);
		$context->remember('news', 'item-1', ['draft' => true]);
		$context->clear();

		$this->assertSame([], $context->forcedFor('blog', 'post-1'));
		$this->assertSame([], $context->forcedFor('news', 'item-1'));
	}
}
