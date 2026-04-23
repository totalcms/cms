<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command\Extension;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * Tests that extension CLI commands cannot override core commands.
 * Simulates the collision detection logic from CliApplication.
 */
describe('CLI command collision protection', function (): void {
	test('extension command with unique name is registered', function (): void {
		$app = new Application();
		$app->addCommand(new class () extends Command {
			protected function configure(): void
			{
				$this->setName('cache:clear');
			}
		});

		$coreNames = array_keys($app->all());

		$extCommand = new class () extends Command {
			protected function configure(): void
			{
				$this->setName('acme:greet');
			}
		};

		$blocked = in_array($extCommand->getName(), $coreNames, true);

		expect($blocked)->toBeFalse();
	});

	test('extension command that matches core command is blocked', function (): void {
		$app = new Application();
		$app->addCommand(new class () extends Command {
			protected function configure(): void
			{
				$this->setName('cache:clear');
			}
		});
		$app->addCommand(new class () extends Command {
			protected function configure(): void
			{
				$this->setName('schema:list');
			}
		});

		$coreNames = array_keys($app->all());

		// Extension tries to register cache:clear
		$extCommand = new class () extends Command {
			protected function configure(): void
			{
				$this->setName('cache:clear');
			}
		};

		$blocked = in_array($extCommand->getName(), $coreNames, true);

		expect($blocked)->toBeTrue();
	});

	test('extension command that matches extension management command is blocked', function (): void {
		$app = new Application();
		$app->addCommand(new class () extends Command {
			protected function configure(): void
			{
				$this->setName('extension:list');
			}
		});
		$app->addCommand(new class () extends Command {
			protected function configure(): void
			{
				$this->setName('extension:enable');
			}
		});

		$coreNames = array_keys($app->all());

		$extCommand = new class () extends Command {
			protected function configure(): void
			{
				$this->setName('extension:list');
			}
		};

		$blocked = in_array($extCommand->getName(), $coreNames, true);

		expect($blocked)->toBeTrue();
	});

	test('multiple extension commands filtered correctly', function (): void {
		$app = new Application();
		$app->addCommand(new class () extends Command {
			protected function configure(): void
			{
				$this->setName('cache:clear');
			}
		});
		$app->addCommand(new class () extends Command {
			protected function configure(): void
			{
				$this->setName('info');
			}
		});

		$coreNames = array_keys($app->all());

		$extCommands = [
			new class () extends Command {
				protected function configure(): void
				{
					$this->setName('acme:safe');
				}
			},
			new class () extends Command {
				protected function configure(): void
				{
					$this->setName('cache:clear');
				}
			},
			new class () extends Command {
				protected function configure(): void
				{
					$this->setName('acme:also-safe');
				}
			},
			new class () extends Command {
				protected function configure(): void
				{
					$this->setName('info');
				}
			},
		];

		$registered = [];
		$blocked    = [];

		foreach ($extCommands as $command) {
			$name = $command->getName();
			if ($name !== null && in_array($name, $coreNames, true)) {
				$blocked[] = $name;
			} else {
				$registered[] = $name;
			}
		}

		expect($registered)->toBe(['acme:safe', 'acme:also-safe']);
		expect($blocked)->toBe(['cache:clear', 'info']);
	});

	test('Symfony built-in commands like help and list are protected', function (): void {
		$app = new Application();
		// Symfony registers 'help' and 'list' by default
		$coreNames = array_keys($app->all());

		expect(in_array('help', $coreNames, true))->toBeTrue();
		expect(in_array('list', $coreNames, true))->toBeTrue();

		$extCommand = new class () extends Command {
			protected function configure(): void
			{
				$this->setName('list');
			}
		};

		$blocked = in_array($extCommand->getName(), $coreNames, true);

		expect($blocked)->toBeTrue();
	});
});
