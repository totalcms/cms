<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use DI\Container;
use Odan\Session\MemorySession;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\SetupAdminCommand;
use TotalCMS\Domain\Auth\Service\FirstLoginChecker;
use TotalCMS\Domain\Setup\Repository\SetupStateRepository;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\TotalCMS;

/*
 * `tcms setup:admin` creates the first admin and finishes setup, so a site
 * made by Total CMS Studio opens ready to sign in. FirstLoginChecker is
 * stubbed (its own tests cover what the stored user looks like); setup state
 * is real, in a temporary data folder.
 */

require_once __DIR__ . '/helpers.php';

beforeEach(function (): void {
	$this->datadir = sys_get_temp_dir() . '/setup-admin-' . bin2hex(random_bytes(4));
	mkdir($this->datadir . '/.system', 0o777, true);
	$config           = createTestConfig(['datadir' => $this->datadir, 'auth' => ['collection' => 'auth']]);
	$this->session    = new MemorySession();
	$this->setupState = new SetupStateManager($this->session, $config, new SetupStateRepository($config));
	$this->users      = [];

	$checker = $this->createMock(FirstLoginChecker::class);
	$checker->method('createFirstUser')->willReturnCallback(function (string $email, string $password, string $name): void {
		if ($this->users !== []) {
			throw new \DomainException('The first user already exists.');
		}
		$this->users[] = ['email' => $email, 'password' => $password, 'name' => $name];
	});

	$container = new Container();
	$container->set(FirstLoginChecker::class, $checker);
	$container->set(SetupStateManager::class, $this->setupState);
	$totalcms = $this->createMock(TotalCMS::class);
	$totalcms->method('container')->willReturn($container);

	$console = new Application();
	$console->addCommand(new SetupAdminCommand($totalcms));
	$this->tester = new CommandTester($console->find('setup:admin'));
});

afterEach(function (): void {
	exec('rm -rf ' . escapeshellarg($this->datadir));
});

/** @param array<string,mixed> $options */
function runSetupAdmin(CommandTester $tester, array $options, ?string $stdin = 'correct horse'): int
{
	if ($stdin !== null) {
		$tester->setInputs([$stdin]);
	}

	return $tester->execute($options, ['interactive' => false]);
}

test('creates the first admin and finishes setup', function (): void {
	$status = runSetupAdmin($this->tester, ['--email' => 'joe@example.test', '--name' => 'Joe', '--password-stdin' => true]);

	expect($status)->toBe(0)
		->and($this->users)->toBe([['email' => 'joe@example.test', 'password' => 'correct horse', 'name' => 'Joe']])
		->and($this->setupState->isSetupComplete())->toBeTrue();
});

test('names the admin Admin when no name is given', function (): void {
	runSetupAdmin($this->tester, ['--email' => 'joe@example.test', '--password-stdin' => true]);

	expect($this->users[0]['name'])->toBe('Admin');
});

test('refuses when an admin already exists', function (): void {
	runSetupAdmin($this->tester, ['--email' => 'joe@example.test', '--password-stdin' => true]);
	$status = runSetupAdmin($this->tester, ['--email' => 'other@example.test', '--password-stdin' => true]);

	expect($status)->toBe(1)
		->and($this->tester->getDisplay())->toContain('This site already has an admin account. Sign in at /admin instead.')
		->and($this->users)->toHaveCount(1);
});

test('rejects a bad email', function (): void {
	$status = runSetupAdmin($this->tester, ['--email' => 'not-an-email', '--password-stdin' => true]);

	expect($status)->toBe(1)
		->and($this->tester->getDisplay())->toContain("That isn't a valid email address.")
		->and($this->users)->toBe([])
		->and($this->setupState->isSetupComplete())->toBeFalse();
});

test('rejects a short password', function (): void {
	$status = runSetupAdmin($this->tester, ['--email' => 'joe@example.test', '--password-stdin' => true], 'short');

	expect($status)->toBe(1)
		->and($this->tester->getDisplay())->toContain('The password must be at least 8 characters.')
		->and($this->users)->toBe([])
		->and($this->setupState->isSetupComplete())->toBeFalse();
});

test('requires the password on stdin', function (): void {
	$status = runSetupAdmin($this->tester, ['--email' => 'joe@example.test']);

	expect($status)->toBe(1)
		->and($this->tester->getDisplay())->toContain('Pass the password on stdin with --password-stdin.')
		->and($this->users)->toBe([]);
});

test('json output on success', function (): void {
	runSetupAdmin($this->tester, ['--email' => 'joe@example.test', '--name' => 'Joe', '--password-stdin' => true, '--json' => true]);

	expect(json_decode($this->tester->getDisplay(), true))->toBe(['created' => true, 'email' => 'joe@example.test', 'name' => 'Joe']);
});

test('json output on error', function (): void {
	runSetupAdmin($this->tester, ['--email' => 'nope', '--password-stdin' => true, '--json' => true]);

	expect(json_decode($this->tester->getDisplay(), true))->toBe(['error' => "That isn't a valid email address."]);
});

test('the password is not an option', function (): void {
	$command = new SetupAdminCommand($this->createMock(TotalCMS::class));

	expect($command->getDefinition()->hasOption('password'))->toBeFalse();
});
