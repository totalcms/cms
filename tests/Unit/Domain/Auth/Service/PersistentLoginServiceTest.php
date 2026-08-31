<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use Odan\Session\FlashInterface;
use Odan\Session\SessionInterface;
use Odan\Session\SessionManagerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Auth\Service\PersistentLoginService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * PersistentLoginService is the "remember me" restore path. AuthMiddleware and
 * DualAuthMiddleware call restoreFromPersistentToken() on requests that arrive
 * without a live session, so every branch below runs *before* the user is
 * authenticated. A false positive here is a silent authentication bypass: the
 * caller gets AUTH_USER written into their session without ever proving a
 * password. These tests therefore assert on the negative space — what must NOT
 * restore — at least as hard as on the happy path.
 */

// ---------------------------------------------------------------- helpers --
// Every global function here is prefixed `persistentLogin` — Pest loads all
// test files into one process, so unprefixed helper names collide.

/**
 * Per-test sandbox directory. Each test gets its own so a stray unlink() in one
 * test can never influence another (and so a bug that deletes the wrong file is
 * visible rather than masked by a shared dir).
 */
function persistentLoginTmpDir(?string $set = null): string
{
	static $dir = '';

	if ($set !== null) {
		$dir = $set;
	}

	return $dir;
}

function persistentLoginTokenDir(): string
{
	return persistentLoginTmpDir() . '/persistent_tokens';
}

/** @param array<string,mixed> $authOverrides */
function persistentLoginConfig(array $authOverrides = []): Config
{
	/** @var Config $config */
	$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();

	$config->env     = 'test';
	$config->tmpdir  = persistentLoginTmpDir();
	$config->datadir = persistentLoginTmpDir();
	$config->auth    = array_merge([
		'enable'              => true,
		'collection'          => 'auth',
		'persistentLoginDays' => 30,
	], $authOverrides);

	return $config;
}

function persistentLoginLoggerFactory(): LoggerFactory
{
	/** @var LoggerFactory&\PHPUnit\Framework\MockObject\MockObject $factory */
	$factory = test()->createMock(LoggerFactory::class);
	$factory->method('channelLogger')->willReturn(new NullLogger());

	return $factory;
}

/**
 * A user validator that says "this user still exists". The service treats an
 * empty array as "user is gone", so anything non-empty is a live account.
 *
 * @param array<string,mixed>|\Throwable $result
 */
function persistentLoginValidator(array|\Throwable $result = ['id' => 'user-1']): UserValidationService
{
	/** @var UserValidationService&\PHPUnit\Framework\MockObject\MockObject $validator */
	$validator = test()->createMock(UserValidationService::class);

	if ($result instanceof \Throwable) {
		$validator->method('validateUserById')->willThrowException($result);
	} else {
		$validator->method('validateUserById')->willReturn($result);
	}

	return $validator;
}

/** @param array<string,mixed> $authOverrides */
function persistentLoginService(
	SessionInterface $session,
	?UserValidationService $validator = null,
	array $authOverrides = [],
): PersistentLoginService {
	return new PersistentLoginService(
		$session,
		persistentLoginConfig($authOverrides),
		$validator ?? persistentLoginValidator(),
		persistentLoginLoggerFactory(),
	);
}

/**
 * Write a token file exactly the way createPersistentToken() would, and return
 * the plaintext token that the cookie must carry to match it.
 *
 * Uses bcrypt cost 4 purely for test speed — password_verify() does not care
 * which cost the hash was minted at, so the verification path is unchanged.
 *
 * @param array<string,mixed> $overrides
 */
function persistentLoginWriteToken(string $selector, array $overrides = [], bool $grace = false): string
{
	$plain = str_repeat('b', 64);

	$data = array_merge([
		'user_id'    => 'user-1',
		'collection' => 'auth',
		'token_hash' => password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4]),
		'created_at' => time() - 60,
		'expires_at' => time() + 3600,
	], $overrides);

	foreach ($overrides as $key => $value) {
		if ($value === null) {
			unset($data[$key]);
		}
	}

	$suffix = $grace ? '.grace.json' : '.json';
	file_put_contents(persistentLoginTokenDir() . '/' . $selector . $suffix, json_encode($data));

	return $plain;
}

function persistentLoginCookie(string $value): void
{
	$_COOKIE[PersistentLoginService::PERSISTENT_COOKIE_NAME] = $value;
}

/** A well-formed selector: 32 lowercase hex chars, as bin2hex(random_bytes(16)) mints. */
function persistentLoginSelector(): string
{
	return bin2hex(random_bytes(16));
}

/**
 * Session double that also implements SessionManagerInterface, so the
 * session-fixation guard (regenerateId) in the restore path is exercised.
 */
function persistentLoginManagedSession(): SessionInterface&SessionManagerInterface
{
	return new class implements SessionInterface, SessionManagerInterface {
		/** @var array<string,mixed> */
		public array $data       = [];
		public int $regenerated  = 0;

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

		/** @return array<string,mixed> */
		public function all(): array
		{
			return $this->data;
		}

		/** @param array<string,mixed> $values */
		public function setValues(array $values): void
		{
			foreach ($values as $key => $value) {
				$this->data[$key] = $value;
			}
		}

		public function clear(): void
		{
			$this->data = [];
		}

		public function getFlash(): FlashInterface
		{
			throw new \LogicException('not needed in tests');
		}

		public function start(): void
		{
		}

		public function isStarted(): bool
		{
			return true;
		}

		public function regenerateId(): void
		{
			$this->regenerated++;
		}

		public function destroy(): void
		{
			$this->data = [];
		}

		public function getId(): string
		{
			return 'test-session-id';
		}

		public function getName(): string
		{
			return 'PHPSESSID';
		}

		public function save(): void
		{
		}
	};
}

/** @return array<int,string> */
function persistentLoginTokenFiles(): array
{
	return array_values(array_filter(
		glob(persistentLoginTokenDir() . '/*.json') ?: [],
		static fn (string $file): bool => !str_contains($file, '.grace.json')
	));
}

/** @return array<int,string> */
function persistentLoginGraceFiles(): array
{
	return glob(persistentLoginTokenDir() . '/*.grace.json') ?: [];
}

// ------------------------------------------------------------- lifecycle --

beforeEach(function (): void {
	persistentLoginTmpDir(sys_get_temp_dir() . '/tcms_persistent_' . uniqid('', true));
	// The service creates the token dir in its constructor, but tests seed token
	// files before constructing it, so create it up front.
	mkdir(persistentLoginTokenDir(), 0755, true);
	unset($_COOKIE[PersistentLoginService::PERSISTENT_COOKIE_NAME]);
});

afterEach(function (): void {
	unset($_COOKIE[PersistentLoginService::PERSISTENT_COOKIE_NAME]);

	$dir = persistentLoginTmpDir();
	if ($dir === '' || !is_dir($dir)) {
		return;
	}

	@chmod($dir . '/persistent_tokens', 0755);
	/** @var \SplFileInfo $file */
	foreach (new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		\RecursiveIteratorIterator::CHILD_FIRST
	) as $file) {
		$file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
	}
	@rmdir($dir);
});

// ----------------------------------------------------------- predicates ---

describe('persistent login predicates', function (): void {
	it('reports the session flag only when it is exactly true', function (mixed $stored, bool $expected): void {
		// Middleware branches on this to decide whether a session may be
		// silently extended. A truthy-but-not-true value (a string, 1) must not
		// be mistaken for "this session came from a verified remember-me token".
		$service = persistentLoginService(persistentLoginSession([SessionKeys::AUTH_PERSISTENT_LOGIN => $stored]));

		expect($service->hasPersistentLogin())->toBe($expected);
	})->with([
		'true'         => [true, true],
		'string true'  => ['true', false],
		'integer one'  => [1, false],
		'false'        => [false, false],
	]);

	it('detects the cookie independently of session state', function (): void {
		$service = persistentLoginService(persistentLoginSession());

		expect($service->hasPersistentCookie())->toBeFalse();

		persistentLoginCookie('selector:token');
		expect($service->hasPersistentCookie())->toBeTrue();
	});

	it('treats an empty cookie as absent', function (): void {
		// An empty cookie is what our own clearPersistentCookie() leaves behind
		// on a browser that echoes it back. Treating it as "present" would send
		// the restore path chasing a token that was deliberately revoked.
		persistentLoginCookie('');

		expect(persistentLoginService(persistentLoginSession())->hasPersistentCookie())->toBeFalse();
	});

	it('ors the session flag with the cookie', function (): void {
		$service = persistentLoginService(persistentLoginSession());
		expect($service->hasPersistentLoginOrCookie())->toBeFalse();

		persistentLoginCookie('selector:token');
		expect($service->hasPersistentLoginOrCookie())->toBeTrue();

		unset($_COOKIE[PersistentLoginService::PERSISTENT_COOKIE_NAME]);
		expect(persistentLoginService(
			persistentLoginSession([SessionKeys::AUTH_PERSISTENT_LOGIN => true])
		)->hasPersistentLoginOrCookie())->toBeTrue();
	});
});

// ------------------------------------------------------ token minting -----

describe('createPersistentToken', function (): void {
	it('creates its token directory on a fresh install', function (): void {
		// tmpdir is routinely wiped (deploys, tmpreaper, a fresh install). If
		// the service did not recreate the directory, every remember-me write
		// would silently no-op and the feature would fail open into "no token".
		rmdir(persistentLoginTokenDir());

		$service = persistentLoginService(persistentLoginSession([
			SessionKeys::AUTH_USER       => 'user-1',
			SessionKeys::AUTH_COLLECTION => 'auth',
		]));

		expect(persistentLoginTokenDir())->toBeDirectory()
			->and($service->createPersistentToken())->toBeString();
	});

	it('refuses to mint a token for an unauthenticated session', function (array $session): void {
		// A token minted without both identity halves would restore *something*
		// later — either a null user or a user in an unknown collection. Both
		// are authentication decisions made from incomplete data.
		$service = persistentLoginService(persistentLoginSession($session));

		expect($service->createPersistentToken())->toBeNull()
			->and(persistentLoginTokenFiles())->toBeEmpty();
	})->with([
		'no user'       => [[SessionKeys::AUTH_COLLECTION => 'auth']],
		'no collection' => [[SessionKeys::AUTH_USER => 'user-1']],
		'empty session' => [[]],
	]);

	it('stores only a hash, never the token the cookie carries', function (): void {
		// The token file sits in tmpdir. If the plaintext token were stored
		// there, anyone who can read tmp (a shared host, a backup, a directory
		// traversal elsewhere) could forge the cookie for every logged-in user.
		$service = persistentLoginService(persistentLoginSession([
			SessionKeys::AUTH_USER       => 'user-1',
			SessionKeys::AUTH_COLLECTION => 'auth',
		]));

		$selector = $service->createPersistentToken();

		expect($selector)->toBeString()
			->and($selector)->toMatch('/^[a-f0-9]{32}$/');

		$files = persistentLoginTokenFiles();
		expect($files)->toHaveCount(1);

		$raw  = (string)file_get_contents($files[0]);
		$data = json_decode($raw, true);

		expect($data['user_id'])->toBe('user-1')
			->and($data['collection'])->toBe('auth')
			->and($data['token_hash'])->toStartWith('$2y$')
			->and(password_verify('wrong-token', $data['token_hash']))->toBeFalse()
			->and($data)->not->toHaveKey('token');
	});

	it('never reuses a selector', function (): void {
		// Selectors are the filename. A collision would let one user's rotation
		// overwrite another user's token file.
		$service = persistentLoginService(persistentLoginSession([
			SessionKeys::AUTH_USER       => 'user-1',
			SessionKeys::AUTH_COLLECTION => 'auth',
		]));

		$first  = $service->createPersistentToken();
		$second = $service->createPersistentToken();

		expect($first)->not->toBe($second)
			->and(persistentLoginTokenFiles())->toHaveCount(2);
	});

	it('honours the configured persistentLoginDays lifetime', function (): void {
		// An operator lowering this to 1 day expects tokens to die in a day. If
		// the setting were ignored, revocation-by-expiry silently stops working.
		$service = persistentLoginService(persistentLoginSession([
			SessionKeys::AUTH_USER       => 'user-1',
			SessionKeys::AUTH_COLLECTION => 'auth',
		]), null, ['persistentLoginDays' => 1]);

		$service->createPersistentToken();

		$data = json_decode((string)file_get_contents(persistentLoginTokenFiles()[0]), true);

		expect($data['expires_at'])->toBeGreaterThan(time() + 86300)
			->and($data['expires_at'])->toBeLessThanOrEqual(time() + 86400);
	});
});

// ---------------------------------------------------- restore: refusals ---

describe('restoreFromPersistentToken refuses', function (): void {
	it('when a session is already authenticated', function (): void {
		// Restoring over a live session would let a stale cookie swap the
		// current user out from under an authenticated request.
		$session  = persistentLoginSession([SessionKeys::AUTH_USER => 'someone-else']);
		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector);
		persistentLoginCookie($selector . ':' . $token);

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeFalse()
			->and($session->get(SessionKeys::AUTH_USER))->toBe('someone-else');
	});

	it('when there is no cookie at all', function (): void {
		expect(persistentLoginService(persistentLoginSession())->restoreFromPersistentToken())->toBeFalse();
	});

	it('when the cookie is not selector:token shaped', function (string $cookie): void {
		// Without the split there is no selector to look up; the code must bail
		// rather than fall through with a half-parsed value.
		persistentLoginCookie($cookie);
		$session = persistentLoginSession();

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeFalse()
			->and($session->has(SessionKeys::AUTH_USER))->toBeFalse();
	})->with([
		'no separator'  => ['justoneblob'],
		'selector only' => ['abcdef0123456789abcdef0123456789'],
	]);

	it('a selector that is not exactly 32 lowercase hex chars', function (string $selector): void {
		// The selector becomes a filename that is both read and @unlink()ed. An
		// attacker fully controls the cookie, so anything that is not our own
		// minted format must be rejected before it reaches the filesystem.
		persistentLoginCookie($selector . ':' . str_repeat('b', 64));
		$session = persistentLoginSession();

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeFalse()
			->and($session->has(SessionKeys::AUTH_USER))->toBeFalse();
	})->with([
		'traversal'        => ['../../etc/passwd'],
		'dot segment'      => ['..'],
		'too short'        => [str_repeat('a', 31)],
		'too long'         => [str_repeat('a', 33)],
		'uppercase hex'    => [strtoupper(str_repeat('ab', 16))],
		'non hex'          => [str_repeat('z', 32)],
		'null byte'        => ["abcdef0123456789abcdef0123456789\0"],
		'trailing newline' => ["abcdef0123456789abcdef0123456789\n"],
		'empty'            => [''],
	]);

	it('a traversal selector without touching the file it points at', function (): void {
		// tokenDir is <tmp>/persistent_tokens, so the selector `../victim`
		// resolves to <tmp>/victim.json. Before the format guard, the failure
		// path would have @unlink()ed it — arbitrary .json deletion from an
		// unauthenticated request.
		$victim = persistentLoginTmpDir() . '/victim.json';
		file_put_contents($victim, '{"keep":true}');

		persistentLoginCookie('../victim:' . str_repeat('b', 64));

		expect(persistentLoginService(persistentLoginSession())->restoreFromPersistentToken())->toBeFalse()
			->and($victim)->toBeReadableFile()
			->and(file_get_contents($victim))->toBe('{"keep":true}');
	});

	it('a well-formed selector with no token file, leaving the cookie alone', function (): void {
		// A missing file is ambiguous: it may mean "forged selector", but it
		// also means "a concurrent request just rotated this token and already
		// sent the replacement cookie". Clearing the cookie here would log the
		// user out on every parallel request (image/XHR bursts).
		$selector = persistentLoginSelector();
		persistentLoginCookie($selector . ':' . str_repeat('b', 64));
		$session = persistentLoginSession();

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeFalse()
			->and($session->has(SessionKeys::AUTH_USER))->toBeFalse()
			->and($_COOKIE[PersistentLoginService::PERSISTENT_COOKIE_NAME])->toBe($selector . ':' . str_repeat('b', 64));
	});

	it('a token whose stored hash does not match, and burns the token', function (): void {
		// This is the stolen-selector case: the attacker learned the filename
		// (a leaked directory listing, a log line) but not the secret half.
		// Restoring would be a full bypass; keeping the file would let them
		// keep guessing.
		$selector = persistentLoginSelector();
		persistentLoginWriteToken($selector);
		persistentLoginCookie($selector . ':' . str_repeat('c', 64));
		$session = persistentLoginSession();

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeFalse()
			->and($session->has(SessionKeys::AUTH_USER))->toBeFalse()
			->and(persistentLoginTokenDir() . '/' . $selector . '.json')->not->toBeReadableFile();
	});

	it('an empty token half, which must not satisfy the hash check', function (): void {
		// password_verify('', $hash) is false for any real hash — this pins that
		// a cookie of `selector:` can never authenticate.
		$selector = persistentLoginSelector();
		persistentLoginWriteToken($selector);
		persistentLoginCookie($selector . ':');

		expect(persistentLoginService(persistentLoginSession())->restoreFromPersistentToken())->toBeFalse();
	});

	it('token data missing any required key', function (string $missing): void {
		// isValidTokenData() is the structural gate before password_verify() and
		// the expiry check. A file missing expires_at would otherwise be treated
		// as expiring at epoch-zero-or-worse; one missing collection would put
		// the user in an unknown collection. Neither may reach the session.
		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector, [$missing => null]);
		persistentLoginCookie($selector . ':' . $token);
		$session = persistentLoginSession();

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeFalse()
			->and($session->has(SessionKeys::AUTH_USER))->toBeFalse()
			->and(persistentLoginTokenDir() . '/' . $selector . '.json')->not->toBeReadableFile();
	})->with(['user_id', 'collection', 'token_hash', 'created_at', 'expires_at']);

	it('a token file it cannot read', function (): void {
		// Wrong ownership after a botched deploy, or a partially restored
		// backup: an unreadable token must fail closed, never fall through to
		// the session writes with an empty $tokenData.
		if (function_exists('posix_getuid') && posix_getuid() === 0) {
			test()->markTestSkipped('running as root ignores file permissions');
		}

		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector);
		chmod(persistentLoginTokenDir() . '/' . $selector . '.json', 0000);
		persistentLoginCookie($selector . ':' . $token);
		$session = persistentLoginSession();

		set_error_handler(static fn (): bool => true, E_WARNING);
		$result = persistentLoginService($session)->restoreFromPersistentToken();
		restore_error_handler();

		expect($result)->toBeFalse()
			->and($session->has(SessionKeys::AUTH_USER))->toBeFalse();
	});

	it('a token file that is not valid JSON', function (): void {
		// A truncated write (disk full, crash mid-write) must fail closed.
		$selector = persistentLoginSelector();
		file_put_contents(persistentLoginTokenDir() . '/' . $selector . '.json', '{"user_id": "user-1"');
		persistentLoginCookie($selector . ':' . str_repeat('b', 64));
		$session = persistentLoginSession();

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeFalse()
			->and($session->has(SessionKeys::AUTH_USER))->toBeFalse();
	});

	it('an expired token, and deletes it', function (): void {
		// Expiry is the only revocation mechanism a user has after losing a
		// device. If an expired token still restored, "log out everywhere"
		// would never actually take effect.
		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector, ['expires_at' => time() - 1]);
		persistentLoginCookie($selector . ':' . $token);
		$session = persistentLoginSession();

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeFalse()
			->and($session->has(SessionKeys::AUTH_USER))->toBeFalse()
			->and(persistentLoginTokenDir() . '/' . $selector . '.json')->not->toBeReadableFile();
	});

	it('a token for a user that no longer exists, and deletes it', function (): void {
		// Deleting a user must actually lock them out. The session is gone but
		// their remember-me cookie is not — this check is what turns account
		// deletion into revocation.
		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector);
		persistentLoginCookie($selector . ':' . $token);
		$session = persistentLoginSession();

		$service = persistentLoginService($session, persistentLoginValidator([]));

		expect($service->restoreFromPersistentToken())->toBeFalse()
			->and($session->has(SessionKeys::AUTH_USER))->toBeFalse()
			->and(persistentLoginTokenDir() . '/' . $selector . '.json')->not->toBeReadableFile();
	});

	it('when user validation blows up rather than failing open', function (): void {
		// A missing collection, a corrupt index, a storage error — any throw
		// from the validator must deny, never fall through to the session
		// writes below it.
		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector);
		persistentLoginCookie($selector . ':' . $token);
		$session = persistentLoginSession();

		$service = persistentLoginService($session, persistentLoginValidator(new \RuntimeException('index unavailable')));

		expect($service->restoreFromPersistentToken())->toBeFalse()
			->and($session->has(SessionKeys::AUTH_USER))->toBeFalse();
	});
});

// ----------------------------------------------------- restore: success ---

describe('restoreFromPersistentToken succeeds', function (): void {
	it('restores the exact identity recorded in the token', function (): void {
		// The restored user and collection must come from the token file, not
		// from anything the request supplied — the cookie only proves ownership
		// of the secret half.
		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector, ['user_id' => 'editor-7', 'collection' => 'members']);
		persistentLoginCookie($selector . ':' . $token);

		$session = persistentLoginManagedSession();
		$service = persistentLoginService($session);

		expect($service->restoreFromPersistentToken())->toBeTrue()
			->and($session->get(SessionKeys::AUTH_USER))->toBe('editor-7')
			->and($session->get(SessionKeys::AUTH_COLLECTION))->toBe('members')
			->and($session->get(SessionKeys::AUTH_PERSISTENT_LOGIN))->toBeTrue()
			->and($session->get(SessionKeys::LAST_ACTIVITY))->toBeInt();
	});

	it('regenerates the session id before elevating to authenticated', function (): void {
		// Restore is a privilege boundary. Without regeneration, an attacker who
		// planted a session id (fixation) rides along into the authenticated
		// session the moment the victim's remember-me cookie is honoured.
		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector);
		persistentLoginCookie($selector . ':' . $token);

		$session = persistentLoginManagedSession();

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeTrue()
			->and($session->regenerated)->toBe(1);
	});

	it('rotates the token: old file retired to grace, a fresh one minted', function (): void {
		// Single-use tokens are what limit the damage of a stolen cookie: the
		// stolen value stops working once the victim's browser uses it. If the
		// old file stayed live, theft would be permanent.
		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector);
		persistentLoginCookie($selector . ':' . $token);

		$session = persistentLoginManagedSession();

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeTrue();

		$live = persistentLoginTokenFiles();

		expect($live)->toHaveCount(1)
			->and(basename($live[0]))->not->toBe($selector . '.json')
			->and(persistentLoginTokenDir() . '/' . $selector . '.json')->not->toBeReadableFile();

		$grace = persistentLoginGraceFiles();
		expect($grace)->toHaveCount(1)
			->and(basename($grace[0]))->toBe($selector . '.grace.json');

		$graceData = json_decode((string)file_get_contents($grace[0]), true);
		expect($graceData['grace_until'])->toBeGreaterThan(time())
			->and($graceData['grace_until'])->toBeLessThanOrEqual(time() + 60)
			->and($graceData['user_id'])->toBe('user-1');
	});

	it('honours a grace file when the token file is already rotated away', function (): void {
		// Browsers fire parallel requests. Request A rotates the token and sets
		// the new cookie; request B is already in flight with the old one. The
		// grace window is what stops B from being treated as a forgery and
		// bouncing the user to the login screen.
		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector, ['grace_until' => time() + 30], grace: true);
		persistentLoginCookie($selector . ':' . $token);

		$session = persistentLoginManagedSession();

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeTrue()
			->and($session->get(SessionKeys::AUTH_USER))->toBe('user-1');
	});

	it('refuses a grace file whose window has closed', function (): void {
		// The grace window must be a narrow concurrency allowance, not a second
		// lifetime for a retired token. Once it lapses the old secret is dead.
		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector, ['grace_until' => time() - 1], grace: true);
		persistentLoginCookie($selector . ':' . $token);

		$session = persistentLoginManagedSession();

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeFalse()
			->and($session->has(SessionKeys::AUTH_USER))->toBeFalse();
	});

	it('refuses a grace file with no grace_until at all', function (): void {
		// A grace file written by an older version (or hand-crafted) without the
		// window must default to "expired", not to "unlimited".
		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector, [], grace: true);
		persistentLoginCookie($selector . ':' . $token);

		expect(persistentLoginService(persistentLoginManagedSession())->restoreFromPersistentToken())->toBeFalse();
	});

	it('still refuses a grace file with a bad token half', function (): void {
		// The grace path skips the missing token file but must not skip the
		// hash check — otherwise knowing a retired selector would be enough.
		$selector = persistentLoginSelector();
		persistentLoginWriteToken($selector, ['grace_until' => time() + 30], grace: true);
		persistentLoginCookie($selector . ':' . str_repeat('c', 64));

		$session = persistentLoginManagedSession();

		expect(persistentLoginService($session)->restoreFromPersistentToken())->toBeFalse()
			->and($session->has(SessionKeys::AUTH_USER))->toBeFalse();
	});

	it('keeps the old token alive when minting the replacement fails', function (): void {
		// If rotation can't write a new token (read-only tmp, full disk), the
		// user must stay logged in on the token they already have rather than be
		// silently locked out with no cookie and no file.
		if (function_exists('posix_getuid') && posix_getuid() === 0) {
			test()->markTestSkipped('running as root ignores directory permissions');
		}

		$selector = persistentLoginSelector();
		$token    = persistentLoginWriteToken($selector);
		persistentLoginCookie($selector . ':' . $token);

		chmod(persistentLoginTokenDir(), 0555);

		// createPersistentToken() calls file_put_contents() unsuppressed, so the
		// failed write emits a PHP warning. Swallow it — the behaviour under
		// test is the recovery, not the warning.
		set_error_handler(static fn (): bool => true, E_WARNING);
		$session = persistentLoginManagedSession();
		$result  = persistentLoginService($session)->restoreFromPersistentToken();
		restore_error_handler();

		chmod(persistentLoginTokenDir(), 0755);

		expect($result)->toBeTrue()
			->and($session->get(SessionKeys::AUTH_USER))->toBe('user-1')
			->and(persistentLoginTokenDir() . '/' . $selector . '.json')->toBeReadableFile()
			->and(persistentLoginGraceFiles())->toBeEmpty();
	});
});

// ---------------------------------------------------------- housekeeping --

describe('cleanupExpiredTokens', function (): void {
	it('deletes expired tokens and keeps live ones', function (): void {
		// This is the only thing that stops tmpdir from accumulating years of
		// credential material. Deleting a live token instead would log every
		// remembered user out.
		$expired = persistentLoginSelector();
		$live    = persistentLoginSelector();
		persistentLoginWriteToken($expired, ['expires_at' => time() - 3600]);
		persistentLoginWriteToken($live, ['expires_at' => time() + 3600]);

		persistentLoginService(persistentLoginSession())->cleanupExpiredTokens();

		expect(persistentLoginTokenDir() . '/' . $expired . '.json')->not->toBeReadableFile()
			->and(persistentLoginTokenDir() . '/' . $live . '.json')->toBeReadableFile();
	});

	it('deletes lapsed grace files and keeps open ones', function (): void {
		// Grace files hold the same hash as a real token. Leaving lapsed ones on
		// disk widens the window in which a leaked file is useful.
		$lapsed = persistentLoginSelector();
		$open   = persistentLoginSelector();
		persistentLoginWriteToken($lapsed, ['grace_until' => time() - 1], grace: true);
		persistentLoginWriteToken($open, ['grace_until' => time() + 30], grace: true);

		persistentLoginService(persistentLoginSession())->cleanupExpiredTokens();

		expect(persistentLoginTokenDir() . '/' . $lapsed . '.grace.json')->not->toBeReadableFile()
			->and(persistentLoginTokenDir() . '/' . $open . '.grace.json')->toBeReadableFile();
	});

	it('does not choke on unreadable or unexpected files', function (): void {
		// Cleanup runs unattended. A single malformed file must not abort the
		// sweep and leave genuinely expired credentials behind.
		$expired = persistentLoginSelector();
		persistentLoginWriteToken($expired, ['expires_at' => time() - 3600]);
		file_put_contents(persistentLoginTokenDir() . '/garbage.json', 'not json{');
		file_put_contents(persistentLoginTokenDir() . '/no-expiry.json', json_encode(['user_id' => 'x']));
		file_put_contents(persistentLoginTokenDir() . '/unreadable.json', '{}');
		chmod(persistentLoginTokenDir() . '/unreadable.json', 0000);

		set_error_handler(static fn (): bool => true, E_WARNING);
		persistentLoginService(persistentLoginSession())->cleanupExpiredTokens();
		restore_error_handler();

		expect(persistentLoginTokenDir() . '/' . $expired . '.json')->not->toBeReadableFile()
			->and(persistentLoginTokenDir() . '/garbage.json')->toBeReadableFile()
			->and(persistentLoginTokenDir() . '/no-expiry.json')->toBeReadableFile();
	});

	it('is a no-op on an empty token directory', function (): void {
		persistentLoginService(persistentLoginSession())->cleanupExpiredTokens();

		expect(persistentLoginTokenFiles())->toBeEmpty();
	});
});

describe('clearPersistentLogin', function (): void {
	it('deletes the server-side token so the cookie cannot be replayed', function (): void {
		// Logout has to kill the token file. Clearing only the cookie would
		// leave a credential on disk that any copy of the cookie still opens.
		$selector = persistentLoginSelector();
		persistentLoginWriteToken($selector);
		persistentLoginCookie($selector . ':' . str_repeat('b', 64));

		persistentLoginService(persistentLoginSession())->clearPersistentLogin();

		expect(persistentLoginTokenDir() . '/' . $selector . '.json')->not->toBeReadableFile();
	});

	it('refuses to delete anything when the cookie selector is not a real selector', function (): void {
		// The selector comes out of a cookie, so it is attacker-controlled, and
		// clearPersistentTokenFile() unlinks "{tokenDir}/{selector}.json". Before
		// the guard, a logged-in user with a remember-me session could rewrite
		// their own cookie to a traversal path and delete any .json file on the
		// way to logout — .system/access-groups.json and apikeys.json are both in
		// range. Reachable through LogoutService::logout().
		$victim = persistentLoginTmpDir() . '/precious.json';
		file_put_contents($victim, '{"keep":"me"}');

		persistentLoginCookie('../precious:' . str_repeat('b', 64));

		persistentLoginService(persistentLoginSession())->clearPersistentLogin();

		expect($victim)->toBeReadableFile();
		expect(file_get_contents($victim))->toBe('{"keep":"me"}');
	});

	it('refuses a selector that only passes because of a trailing newline', function (): void {
		// The guard is anchored with \z rather than $, because $ also matches
		// before a final newline — a selector ending in one would otherwise read
		// as valid to the guard.
		$selector = persistentLoginSelector();
		persistentLoginWriteToken($selector);
		persistentLoginCookie($selector . "\n:" . str_repeat('b', 64));

		persistentLoginService(persistentLoginSession())->clearPersistentLogin();

		expect(persistentLoginTokenDir() . '/' . $selector . '.json')->toBeReadableFile();
	});
	it('leaves other users tokens untouched', function (): void {
		// One user logging out must not revoke anyone else's remember-me.
		$mine   = persistentLoginSelector();
		$theirs = persistentLoginSelector();
		persistentLoginWriteToken($mine);
		persistentLoginWriteToken($theirs);
		persistentLoginCookie($mine . ':' . str_repeat('b', 64));

		persistentLoginService(persistentLoginSession())->clearPersistentLogin();

		expect(persistentLoginTokenDir() . '/' . $theirs . '.json')->toBeReadableFile();
	});

	it('survives being called with no cookie or a malformed one', function (?string $cookie): void {
		// Logout runs on every session end, including ones that never had a
		// remember-me cookie. It must never fatal there.
		if ($cookie !== null) {
			persistentLoginCookie($cookie);
		}

		persistentLoginService(persistentLoginSession())->clearPersistentLogin();

		expect(true)->toBeTrue();
	})->with([
		'no cookie'      => [null],
		'no separator'   => ['garbage'],
	]);
});

/**
 * Plain state-holding session (no SessionManagerInterface), matching what the
 * PhpSession-free unit context gives us.
 *
 * @param array<string,mixed> $data
 */
function persistentLoginSession(array $data = []): SessionInterface
{
	return new InMemorySession($data);
}
