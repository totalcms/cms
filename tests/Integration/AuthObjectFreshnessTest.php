<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

/**
 * Authentication and authorization read users from disk
 * (ObjectRepository::fetchObjectFresh), never from the persistent object
 * cache. A hand edit to a user file (a plain-text password to recover an
 * account, `active: false`, a groups change) applies on the next request,
 * even while a cached copy from an ordinary fetch is still warm. Everything
 * else keeps reading users through the cache.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	// Warm the persistent cache with the user as the fixture has it.
	$this->app->getContainer()->get(ObjectRepository::class)->fetchObject('auth', 'editor-user-test-com');
});

/**
 * Rewrite fields of a fixture user's file directly, as a hand edit would.
 * The cached copy from beforeEach stays warm (rebooting the app would clear
 * APCu, the persistent cache under test), and no fresh read has happened yet.
 *
 * @param array<string,mixed> $changes
 */
function handEditUser(string $id, array $changes): void
{
	$path = cmsDataDir() . "auth/{$id}.json";
	$data = array_merge((array)json_decode((string)file_get_contents($path), true), $changes);
	file_put_contents($path, (string)json_encode($data, JSON_PRETTY_PRINT));
}

describe('authentication', function (): void {
	it('accepts a hand-typed plain-text password while the old user is cached', function (): void {
		handEditUser('editor-user-test-com', ['password' => 'Recovered-Pass-42']);

		$user = $this->app->getContainer()->get(LoginService::class)->authenticate('editor-user@test.com', 'Recovered-Pass-42', 'auth');

		expect($user['id'])->toBe('editor-user-test-com');
	});

	it('sees a hand-edited groups change at once', function (): void {
		handEditUser('editor-user-test-com', ['groups' => ['viewer']]);

		$container = $this->app->getContainer();
		expect($container->get(UserValidationService::class)->validateUserById('editor-user-test-com', 'auth')['groups'])->toBe(['viewer']);
		expect($container->get(AccessControlService::class)->canAccessCollection('editor-user-test-com', 'blog', 'delete'))->toBeFalse();
	});
});

describe('everything else', function (): void {
	it('still reads users through the persistent cache', function (): void {
		handEditUser('editor-user-test-com', ['name' => 'Hand Edited']);

		$container = $this->app->getContainer();
		expect($container->get(CacheManager::class)->getComputedData('object:auth:editor-user-test-com'))->toBeArray();
		expect($container->get(ObjectRepository::class)->fetchObject('auth', 'editor-user-test-com')?->toArray()['name'])->toBe('Editor Test User');
	});
});
