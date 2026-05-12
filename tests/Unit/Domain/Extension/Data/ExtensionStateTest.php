<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Data\ExtensionState;

describe('ExtensionState', function (): void {
	test('creates with defaults', function (): void {
		$state = new ExtensionState();

		expect($state->enabled)->toBeFalse();
		expect($state->installedAt)->toBe('');
		expect($state->version)->toBe('');
		expect($state->error)->toBeNull();
		expect($state->permissions)->toBe([]);
	});

	test('creates from array with all fields', function (): void {
		$state = ExtensionState::fromArray([
			'enabled'      => true,
			'installed_at' => '2026-04-15T10:00:00Z',
			'version'      => '1.2.0',
			'error'        => 'boot() failed',
			'permissions'  => [
				'twig:functions' => true,
				'cli:commands'   => false,
			],
		]);

		expect($state->enabled)->toBeTrue();
		expect($state->installedAt)->toBe('2026-04-15T10:00:00Z');
		expect($state->version)->toBe('1.2.0');
		expect($state->error)->toBe('boot() failed');
		expect($state->permissions)->toBe([
			'twig:functions' => true,
			'cli:commands'   => false,
		]);
	});

	test('creates from array with missing fields', function (): void {
		$state = ExtensionState::fromArray([]);

		expect($state->enabled)->toBeFalse();
		expect($state->installedAt)->toBe('');
		expect($state->version)->toBe('');
		expect($state->error)->toBeNull();
		expect($state->permissions)->toBe([]);
	});

	test('casts permission values to booleans', function (): void {
		$state = ExtensionState::fromArray([
			'permissions' => [
				'twig:functions' => 1,
				'cli:commands'   => 0,
				'routes:api'     => 'yes',
			],
		]);

		expect($state->permissions['twig:functions'])->toBeTrue();
		expect($state->permissions['cli:commands'])->toBeFalse();
		expect($state->permissions['routes:api'])->toBeTrue();
	});

	test('converts to array', function (): void {
		$state = new ExtensionState(
			enabled: true,
			installedAt: '2026-04-15T10:00:00Z',
			version: '1.0.0',
			error: null,
			permissions: ['twig:functions' => true, 'cli:commands' => false],
		);

		$array = $state->toArray();

		expect($array)->toBe([
			'enabled'      => true,
			'installed_at' => '2026-04-15T10:00:00Z',
			'version'      => '1.0.0',
			'error'        => null,
			'permissions'  => ['twig:functions' => true, 'cli:commands' => false],
		]);
	});

	test('roundtrips through fromArray and toArray', function (): void {
		$original = [
			'enabled'      => true,
			'installed_at' => '2026-04-15T10:00:00Z',
			'version'      => '2.0.0',
			'error'        => 'test error',
			'permissions'  => ['routes:admin' => true, 'events:listen' => false],
		];

		$state  = ExtensionState::fromArray($original);
		$result = $state->toArray();

		expect($result)->toBe($original);
	});

	test('isPermitted returns true for enabled capability', function (): void {
		$state = new ExtensionState(permissions: [
			'twig:functions' => true,
			'cli:commands'   => false,
		]);

		expect($state->isPermitted('twig:functions'))->toBeTrue();
	});

	test('isPermitted returns false for disabled capability', function (): void {
		$state = new ExtensionState(permissions: [
			'twig:functions' => true,
			'cli:commands'   => false,
		]);

		expect($state->isPermitted('cli:commands'))->toBeFalse();
	});

	test('isPermitted returns false for unknown capability', function (): void {
		$state = new ExtensionState(permissions: [
			'twig:functions' => true,
		]);

		expect($state->isPermitted('routes:api'))->toBeFalse();
	});

	test('isPermitted returns true for everything when permissions empty', function (): void {
		$state = new ExtensionState(permissions: []);

		expect($state->isPermitted('twig:functions'))->toBeTrue();
		expect($state->isPermitted('cli:commands'))->toBeTrue();
		expect($state->isPermitted('anything'))->toBeTrue();
	});
});
