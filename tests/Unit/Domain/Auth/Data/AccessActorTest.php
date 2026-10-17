<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Data\AccessActor;

// A session user who logged in at the default `/admin/login` route has an
// empty collection; everywhere else in the codebase that means the default
// auth collection, and authCollection() is the one place that applies it.
final class AccessActorTest extends TestCase
{
	public function testAuthCollectionReturnsTheActorsCollectionWhenSet(): void
	{
		expect((new AccessActor('alex', 'members'))->authCollection('users'))->toBe('members');
	}

	public function testAuthCollectionFallsBackToTheDefaultWhenEmpty(): void
	{
		$actor = new AccessActor('alex', '');

		expect($actor->authCollection('users'))->toBe('users');
		expect($actor->collection)->toBe('');
	}
}
