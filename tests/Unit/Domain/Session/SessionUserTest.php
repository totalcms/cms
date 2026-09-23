<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Session;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Auth\Service\InMemorySession;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Session\SessionUser;

// The logged-in user a session carries: the AUTH_USER / AUTH_COLLECTION pair
// that FileAccessManager, UploadAccessPolicy, AccessManager, the persistent
// login and cms.auth.isSuperAdmin() each used to read for themselves.
final class SessionUserTest extends TestCase
{
	public function testALoggedInSessionYieldsItsUser(): void
	{
		$user = SessionUser::fromSession(new InMemorySession([
			SessionKeys::AUTH_USER       => 'joe',
			SessionKeys::AUTH_COLLECTION => 'auth',
		]));

		$this->assertNotNull($user);
		$this->assertSame('joe', $user->id);
		$this->assertSame('auth', $user->collection);
	}

	/** @return iterable<string, array{array<string,mixed>}> */
	public static function anonymousSessions(): iterable
	{
		yield 'empty session'          => [[]];
		yield 'collection only'        => [[SessionKeys::AUTH_COLLECTION => 'auth']];
		yield 'empty user id'          => [[SessionKeys::AUTH_USER => '', SessionKeys::AUTH_COLLECTION => 'auth']];
		yield 'null values'            => [[SessionKeys::AUTH_USER => null, SessionKeys::AUTH_COLLECTION => null]];
	}

	/**
	 * @dataProvider anonymousSessions
	 *
	 * @param array<string,mixed> $data
	 */
	public function testASessionWithoutAUserIdIsAnonymous(array $data): void
	{
		$this->assertNull(SessionUser::fromSession(new InMemorySession($data)));
	}

	public function testTheCollectionMayBeEmptyMeaningTheDefault(): void
	{
		// The test harness and older sessions store '' for the default auth
		// collection; AccessManager substitutes the configured one.
		$user = SessionUser::fromSession(new InMemorySession([SessionKeys::AUTH_USER => 'joe']));

		$this->assertSame('', $user?->collection);
	}

	public function testValuesAreCoercedToStrings(): void
	{
		$user = SessionUser::fromSession(new InMemorySession([
			SessionKeys::AUTH_USER       => 42,
			SessionKeys::AUTH_COLLECTION => 'members',
		]));

		$this->assertSame('42', $user?->id);
	}
}
