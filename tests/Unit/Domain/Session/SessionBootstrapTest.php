<?php

declare(strict_types=1);

use Odan\Session\SessionInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Session\Service\SessionBootstrap;
use TotalCMS\Support\ContainerFactory;

// The host page's session data survives the PhpSession restart under
// `preserve` (the default) and is dropped under `replace` or anything else.
test('preserve carries the host session over', function (): void {
	expect(SessionBootstrap::carriedOver('preserve', ['cart' => [1, 2]]))->toBe(['cart' => [1, 2]]);
});

test('replace and unknown strategies drop it', function (string $strategy): void {
	expect(SessionBootstrap::carriedOver($strategy, ['cart' => [1, 2]]))->toBe([]);
})->with(['replace', 'bogus', '']);

test('nothing is resolved when no session is active', function (): void {
	expect((new SessionBootstrap(new NullLogger()))->resolveConflict('preserve'))->toBe([]);
});

test('restore writes every key back under its original name', function (): void {
	$session = test()->createMock(SessionInterface::class);
	$session->expects(test()->exactly(2))->method('set')
		->with(test()->logicalOr('cart', 'user'), test()->anything());

	(new SessionBootstrap(new NullLogger()))->restore($session, ['cart' => [1], 'user' => 'bob']);
});

// The PHP API (Stacks pages) pulls this out of the container before the
// session starts. LoggerInterface has no default binding, so the service
// needs its own definition or every Stacks page dies on an uninitialized
// session property.
test('resolves from the container', function (): void {
	expect(ContainerFactory::build()->get(SessionBootstrap::class))->toBeInstanceOf(SessionBootstrap::class);
});

// ─── The host's session file survives ───────────────────────────────────────
//
// A Stacks page, or another PHP app, may have opened the session in PHP's
// default folder before Total CMS starts. Total CMS reopens the session in
// its own folder under the same cookie id, and the host's file must stay
// where it was: an app that opens the session with use_strict_mode on
// rejects a cookie id with no file behind it and mints a new one, which logs
// the visitor out of Total CMS on the next page.

/** @return array{0: string, 1: string} two empty, writable session folders */
function sessionBootstrapFolders(): array
{
	$host = sys_get_temp_dir() . '/tcms-host-' . uniqid();
	$ours = sys_get_temp_dir() . '/tcms-ours-' . uniqid();
	mkdir($host, 0700);
	mkdir($ours, 0700);
	test()->folders = [$host, $ours];

	return [$host, $ours];
}

/** Open a session the way a host page does: in its own folder, under a known id. */
function sessionBootstrapHostSession(string $folder, array $data): void
{
	session_save_path($folder);
	session_id('hostsess1');
	session_start();
	$_SESSION = $data;
}

/** A logger that keeps every record so the test can read the level and context back. */
function sessionBootstrapSpyLogger(): AbstractLogger
{
	return new class extends AbstractLogger {
		/** @var list<array{level: string, message: string, context: array<string,mixed>}> */
		public array $records = [];

		public function log($level, string|Stringable $message, array $context = []): void
		{
			$this->records[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
		}
	};
}

// session_save_path() is process-wide. The folder these tests point it at
// is deleted on the way out, so the original path must come back or every
// later session_start() in this worker fails to open its file — which is
// exactly what CI showed when the file shared a worker with the CSRF tests.
beforeEach(function (): void {
	$this->savePathBefore = session_save_path();
});

afterEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	session_id('');
	session_save_path((string)$this->savePathBefore);
	foreach ($this->folders ?? [] as $folder) {
		exec('rm -rf ' . escapeshellarg($folder));
	}
});

test('preserve closes the host session and leaves its file, with its data, where the host opened it', function (): void {
	[$host, $ours] = sessionBootstrapFolders();
	sessionBootstrapHostSession($host, ['cart' => [1, 2]]);

	$carried = (new SessionBootstrap(new NullLogger()))->resolveConflict('preserve', $ours);

	expect($carried)->toBe(['cart' => [1, 2]])
		->and(session_status())->toBe(PHP_SESSION_NONE)
		->and(file_exists("$host/sess_hostsess1"))->toBeTrue()
		->and((string)file_get_contents("$host/sess_hostsess1"))->toContain('cart');
});

test('replace leaves the host file in place but empty, as destroying it used to leave nothing', function (): void {
	[$host, $ours] = sessionBootstrapFolders();
	sessionBootstrapHostSession($host, ['cart' => [1, 2]]);

	$carried = (new SessionBootstrap(new NullLogger()))->resolveConflict('replace', $ours);

	expect($carried)->toBe([])
		->and(file_exists("$host/sess_hostsess1"))->toBeTrue()
		->and(file_get_contents("$host/sess_hostsess1"))->toBe('');
});

test('a session relocated out of the host folder is logged at info with both paths; one in the same folder stays at debug', function (): void {
	[$host, $ours] = sessionBootstrapFolders();
	$logger        = sessionBootstrapSpyLogger();

	sessionBootstrapHostSession($host, ['cart' => [1]]);
	(new SessionBootstrap($logger))->resolveConflict('preserve', $ours);

	expect($logger->records)->toHaveCount(1)
		->and($logger->records[0]['level'])->toBe('info')
		->and($logger->records[0]['context']['hostPath'])->toBe($host)
		->and($logger->records[0]['context']['savePath'])->toBe($ours)
		->and($logger->records[0]['context']['existingKeys'])->toBe(['cart']);

	sessionBootstrapHostSession($host, ['cart' => [1]]);
	(new SessionBootstrap($logger))->resolveConflict('preserve', $host);

	expect($logger->records)->toHaveCount(2)
		->and($logger->records[1]['level'])->toBe('debug');
});
