<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Tests\Unit\Domain\Auth\Service\InMemorySession;
use TotalCMS\Domain\Auth\Exception\ImpersonationException;
use TotalCMS\Domain\Auth\Service\ImpersonationService;
use TotalCMS\Domain\Auth\Service\RegistrationCollections;
use TotalCMS\Domain\Auth\Service\SessionLogin;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * @param array<string, mixed> $session
 * @param list<CollectionData> $collections every collection record; those with the toggle on may register
 *
 * @return array{0: ImpersonationService, 1: InMemorySession, 2: SessionLogin}
 */
function makeImpersonationService(array $session, callable $configure, array $collections = []): array
{
	$sess      = new InMemorySession($session);
	$login     = test()->createMock(SessionLogin::class);
	$users     = test()->createMock(UserValidationService::class);
	$fetch     = test()->createMock(ObjectFetcher::class);
	$cfg       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$cfg->auth = ['collection' => 'auth', 'publicRegistration' => ['members']];
	$lf        = test()->createMock(LoggerFactory::class);
	$lf->method('channelLogger')->willReturn(new NullLogger());

	$fetcher = test()->createMock(CollectionFetcher::class);
	$fetcher->method('fetchCollection')->willReturnCallback(static function (string $id) use ($collections): CollectionData {
		foreach ($collections as $collection) {
			if ($collection->id === $id) {
				return $collection;
			}
		}

		throw new RuntimeException('missing');
	});
	// The real isOrInherits(): an `auth`-schema collection resolves without a read.
	$schemas = test()->createPartialMock(SchemaFetcher::class, ['fetchRawSchema']);
	$schemas->method('fetchRawSchema')->willThrowException(new RuntimeException('no schema'));
	$registration = new RegistrationCollections($cfg, $fetcher, $schemas);

	$configure($users, $fetch, $login);
	$svc = new ImpersonationService($sess, $login, $users, $fetch, $cfg, $registration, $lf);

	return [$svc, $sess, $login];
}

test('start stashes the real identity and swaps to the target', function (): void {
	[$svc, $sess, $login] = makeImpersonationService(
		['totalcms.auth.user' => 'joe', 'totalcms.auth.collection' => 'auth'],
		function ($users, $fetch, $login): void {
			$users->method('isSuperAdmin')->willReturnMap([['joe', 'auth', true], ['jane', 'members', false]]);
			$fetch->method('existsObject')->willReturn(true);
			$login->expects(test()->once())->method('establish')->with('jane', 'members', false);
		},
	);
	$svc->start('members', 'jane');
	expect($sess->get('totalcms.auth.impersonator'))->toBe(['userId' => 'joe', 'collection' => 'auth']);
});

test('start rejects a non-super-admin caller', function (): void {
	[$svc] = makeImpersonationService(
		['totalcms.auth.user' => 'jane', 'totalcms.auth.collection' => 'auth'],
		fn ($users) => $users->method('isSuperAdmin')->willReturn(false),
	);
	expect(fn () => $svc->start('members', 'bob'))->toThrow(ImpersonationException::class);
});

test('start rejects a super-admin target', function (): void {
	[$svc] = makeImpersonationService(
		['totalcms.auth.user' => 'joe', 'totalcms.auth.collection' => 'auth'],
		function ($users, $fetch): void {
			$users->method('isSuperAdmin')->willReturnMap([['joe', 'auth', true], ['admin2', 'auth', true]]);
			$fetch->method('existsObject')->willReturn(true);
		},
	);
	expect(fn () => $svc->start('auth', 'admin2'))->toThrow(ImpersonationException::class);
});

test('start rejects nesting', function (): void {
	[$svc] = makeImpersonationService(
		['totalcms.auth.user' => 'joe', 'totalcms.auth.collection' => 'auth', 'totalcms.auth.impersonator' => ['userId' => 'joe', 'collection' => 'auth']],
		function ($users, $fetch): void {
			$users->method('isSuperAdmin')->willReturn(true);
			$fetch->method('existsObject')->willReturn(true);
		},
	);
	expect(fn () => $svc->start('members', 'jane'))->toThrow(ImpersonationException::class);
});

test('start rejects a target in a collection that is not auth-enabled', function (): void {
	// 'blog' is neither the operator collection nor a public-registration collection,
	// so the server must refuse it even though the object exists.
	[$svc] = makeImpersonationService(
		['totalcms.auth.user' => 'joe', 'totalcms.auth.collection' => 'auth'],
		function ($users, $fetch): void {
			$users->method('isSuperAdmin')->willReturnMap([['joe', 'auth', true]]);
			$fetch->method('existsObject')->willReturn(true);
		},
	);
	expect(fn () => $svc->start('blog', 'some-post'))->toThrow(ImpersonationException::class);
});

test('start accepts a collection opened to registration only by its toggle', function (): void {
	// 'partners' is not in the config list; its own publicRegistration toggle
	// makes it a member collection that may be impersonated.
	$partners                     = new CollectionData();
	$partners->id                 = 'partners';
	$partners->schema             = 'auth';
	$partners->publicRegistration = true;

	[$svc, $sess] = makeImpersonationService(
		['totalcms.auth.user' => 'joe', 'totalcms.auth.collection' => 'auth'],
		function ($users, $fetch, $login): void {
			$users->method('isSuperAdmin')->willReturnMap([['joe', 'auth', true], ['pat', 'partners', false]]);
			$fetch->method('existsObject')->willReturn(true);
			$login->expects(test()->once())->method('establish')->with('pat', 'partners', false);
		},
		[$partners],
	);
	$svc->start('partners', 'pat');
	expect($sess->get('totalcms.auth.impersonator'))->toBe(['userId' => 'joe', 'collection' => 'auth']);
});

test('stop restores the real identity and clears the key regardless of target permissions', function (): void {
	[$svc, $sess, $login] = makeImpersonationService(
		['totalcms.auth.user'       => 'jane', 'totalcms.auth.collection' => 'members', 'totalcms.auth.impersonator' => ['userId' => 'joe', 'collection' => 'auth']],
		fn ($users, $fetch, $login) => $login->expects(test()->once())->method('establish')->with('joe', 'auth', false),
	);
	$svc->stop();
	expect($sess->has('totalcms.auth.impersonator'))->toBeFalse();
});

test('stop is a no-op when not impersonating', function (): void {
	[$svc, $sess, $login] = makeImpersonationService([], function ($u, $f, $login): void {
		$login->expects(test()->never())->method('establish');
	});
	$svc->stop();
	expect($sess->all())->toBe([]);
});
