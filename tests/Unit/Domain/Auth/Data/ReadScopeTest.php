<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Data\ReadScope;
use TotalCMS\Domain\Collection\Data\OwnerScope;

final class ReadScopeTest extends TestCase
{
	/** @var list<array<string,mixed>> */
	private const ITEMS = [
		['id' => 'mine', 'owner' => 'alex', 'draft' => true],
		['id' => 'theirs', 'owner' => 'sam', 'draft' => false],
		['id' => 'their-draft', 'owner' => 'sam', 'draft' => true],
		['id' => 'untagged', 'owner' => 'sam'],
	];

	/**
	 * @return list<string>
	 */
	private function ids(ReadScope $scope): array
	{
		return array_column($scope->filter(self::ITEMS), 'id');
	}

	public function testAllKeepsEverything(): void
	{
		$this->assertTrue(ReadScope::all()->isAll());
		$this->assertSame(['mine', 'theirs', 'their-draft', 'untagged'], $this->ids(ReadScope::all()));
	}

	public function testPublishedOnlyDropsDrafts(): void
	{
		$this->assertFalse(ReadScope::publishedOnly()->seesDrafts());
		$this->assertSame(['theirs', 'untagged'], $this->ids(ReadScope::publishedOnly()));
	}

	public function testNoneKeepsNothing(): void
	{
		$this->assertSame([], $this->ids(ReadScope::none()));
	}

	public function testOwnPlusPublishedKeepsTheOwnersDraftsButNotOthers(): void
	{
		$scope = new ReadScope(new OwnerScope('owner', 'alex'), published: true, drafts: false);

		$this->assertTrue($scope->seesDrafts());
		$this->assertSame(['mine', 'theirs', 'untagged'], $this->ids($scope));
	}

	public function testOwnOnlyKeepsJustTheOwners(): void
	{
		$scope = new ReadScope(new OwnerScope('owner', 'alex'), published: false, drafts: false);

		$this->assertSame(['mine'], $this->ids($scope));
	}

	public function testDraftAcceptsImportedTruthyStringsOnly(): void
	{
		$this->assertTrue(ReadScope::isDraft(['draft' => 'true']));
		$this->assertTrue(ReadScope::isDraft(['draft' => 1]));
		$this->assertFalse(ReadScope::isDraft(['draft' => 'false']));
		$this->assertFalse(ReadScope::isDraft(['draft' => '']));
		$this->assertFalse(ReadScope::isDraft([]));
	}

	public function testEachScopeHasItsOwnCacheKey(): void
	{
		$keys = [
			ReadScope::publishedOnly()->cacheKey(),
			ReadScope::none()->cacheKey(),
			(new ReadScope(new OwnerScope('owner', 'alex'), published: true, drafts: false))->cacheKey(),
			(new ReadScope(new OwnerScope('owner', 'alex'), published: false, drafts: false))->cacheKey(),
			(new ReadScope(new OwnerScope('owner', 'sam'), published: false, drafts: false))->cacheKey(),
		];

		$this->assertCount(5, array_unique($keys));
	}
}
