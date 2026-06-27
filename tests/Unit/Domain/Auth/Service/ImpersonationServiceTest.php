<?php

declare(strict_types=1);

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Auth\Exception\ImpersonationException;
use TotalCMS\Domain\Auth\Service\ImpersonationService;
use TotalCMS\Domain\Auth\Service\SessionLogin;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;
use TotalCMS\Factory\LoggerFactory;
use Psr\Log\NullLogger;

/**
 * Minimal in-memory SessionInterface stub that keeps state as a plain array
 * on an object, so mutations inside service calls are visible to the test.
 */
final class InMemorySession implements SessionInterface
{
	/** @var array<string, mixed> */
	private array $data;

	/** @param array<string, mixed> $initial */
	public function __construct(array $initial = [])
	{
		$this->data = $initial;
	}

	public function get(string $key, mixed $default = null): mixed
	{
		return $this->data[$key] ?? $default;
	}

	public function set(string $key, mixed $value): void
	{
		$this->data[$key] = $value;
	}

	public function has(string $key): bool
	{
		return isset($this->data[$key]);
	}

	public function delete(string $key): void
	{
		unset($this->data[$key]);
	}

	/** @return array<string, mixed> */
	public function all(): array
	{
		return $this->data;
	}

	public function setValues(array $values): void
	{
		foreach ($values as $k => $v) {
			$this->data[$k] = $v;
		}
	}

	public function clear(): void
	{
		$this->data = [];
	}

	public function getFlash(): \Odan\Session\FlashInterface
	{
		throw new \LogicException('not needed in tests');
	}
}

/**
 * @param array<string, mixed> $session
 * @return array{0: ImpersonationService, 1: InMemorySession, 2: SessionLogin}
 */
function makeImpersonationService(array $session, callable $configure): array {
	$sess  = new InMemorySession($session);
	$login = test()->createMock(SessionLogin::class);
	$users = test()->createMock(UserValidationService::class);
	$fetch = test()->createMock(ObjectFetcher::class);
	$cfg   = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$cfg->auth = ['collection' => 'auth', 'publicRegistration' => ['members']];
	$lf    = test()->createMock(LoggerFactory::class);
	$lf->method('channelLogger')->willReturn(new NullLogger());

	$configure($users, $fetch, $login);
	$svc = new ImpersonationService($sess, $login, $users, $fetch, $cfg, $lf);
	return [$svc, $sess, $login];
}

test('start stashes the real identity and swaps to the target', function (): void {
	[$svc, $sess, $login] = makeImpersonationService(
		['totalcms.auth.user' => 'joe', 'totalcms.auth.collection' => 'auth'],
		function ($users, $fetch, $login): void {
			$users->method('isSuperAdmin')->willReturnMap([['joe', true], ['jane', false]]);
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
			$users->method('isSuperAdmin')->willReturnMap([['joe', true], ['admin2', true]]);
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
			$users->method('isSuperAdmin')->willReturnMap([['joe', true]]);
			$fetch->method('existsObject')->willReturn(true);
		},
	);
	expect(fn () => $svc->start('blog', 'some-post'))->toThrow(ImpersonationException::class);
});

test('stop restores the real identity and clears the key regardless of target permissions', function (): void {
	[$svc, $sess, $login] = makeImpersonationService(
		['totalcms.auth.user' => 'jane', 'totalcms.auth.collection' => 'members', 'totalcms.auth.impersonator' => ['userId' => 'joe', 'collection' => 'auth']],
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
