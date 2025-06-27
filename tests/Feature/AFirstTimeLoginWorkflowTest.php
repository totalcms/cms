<?php

use TotalCMS\Domain\Auth\Service\FirstLoginChecker;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('First Time Login Workflow', function () {
	it('detects new installation correctly when no users exist', function (): void {
		// Ensure clean state for the start of the workflow tests
		recursiveDelete(cmsDataDir());
		$container    = $this->app->getContainer();
		$cacheManager = $container->get(CacheManager::class);
		$cacheManager->clearAllCaches();

		$firstLoginChecker = $container->get(FirstLoginChecker::class);

		// On a fresh installation, should detect as new
		expect($firstLoginChecker->isNewInstallation())->toBeTrue();
	});

	it('automatically creates auth collection when checking for new installation', function (): void {
		$container         = $this->app->getContainer();
		$collectionFetcher = $container->get(CollectionFetcher::class);
		$firstLoginChecker = $container->get(FirstLoginChecker::class);
		$config            = $container->get('TotalCMS\Support\Config');
		$authCollection    = $config->auth['collection'];

		// Check for new installation - this should trigger auth collection creation
		$isNew = $firstLoginChecker->isNewInstallation();
		expect($isNew)->toBeTrue();

		// After the check, auth collection should exist (auto-created for reserved collections)
		$collection = $collectionFetcher->fetchCollection($authCollection);
		expect($collection)->not()->toBeNull();
		expect($collection->id)->toBe($authCollection);
	});

	it('creates first user when authenticating on new installation', function (): void {
		$container    = $this->app->getContainer();
		$loginService = $container->get(LoginService::class);

		$email    = 'admin@test.com';
		$password = 'secure-password-123';

		// Perform first login - should create user
		$user = $loginService->authenticate($email, $password);

		// Verify user was created with correct data
		expect($user)->toBeArray();
		expect($user['id'])->toBe('admin');
		expect($user['name'])->toBe('Admin');
		expect($user['email'])->toBe($email);
		expect($user['active'])->toBe(true);
		expect($user['groups'])->toContain(UserValidationService::ADMINGROUP);
		expect(password_verify($password, $user['password']))->toBeTrue();
	});

	it('does not create duplicate users on subsequent logins', function (): void {
		$container         = $this->app->getContainer();
		$loginService      = $container->get(LoginService::class);
		$firstLoginChecker = $container->get(FirstLoginChecker::class);
		$indexReader       = $container->get(IndexReader::class);
		$config            = $container->get('TotalCMS\Support\Config');
		$authCollection    = $config->auth['collection'];

		$email    = 'admin@test.com';
		$password = 'secure-password-123';

		// First check what the index shows
		$index           = $indexReader->fetchIndex($authCollection);
		$userCountBefore = $index->objects->count();
		expect($userCountBefore)->toBe(1);

		// Should no longer be a new installation (user was created in previous test)
		expect($firstLoginChecker->isNewInstallation())->toBeFalse();

		// Login attempt with same credentials should not create new user
		$user = $loginService->authenticate($email, $password);
		expect($user['email'])->toBe($email);

		// User count should remain the same
		$index          = $indexReader->fetchIndex($authCollection);
		$userCountAfter = $index->objects->count();
		expect($userCountAfter)->toBe(1);
	});

	it('handles auth collection creation gracefully', function (): void {
		$container         = $this->app->getContainer();
		$firstLoginChecker = $container->get(FirstLoginChecker::class);

		// isNewInstallation should not throw exceptions even with missing collections
		expect(fn () => $firstLoginChecker->isNewInstallation())->not()->toThrow(Exception::class);

		// The method should handle the case gracefully
		$result = $firstLoginChecker->isNewInstallation();
		expect($result)->toBeIn([true, false]); // Should return a boolean
	});
});
