<?php

use TotalCMS\Domain\Auth\Service\FirstLoginChecker;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;

beforeAll(function (): void {
	// The fresh-installation state this whole file needs. It used to be created
	// as a side effect of the first test, which made every later test depend on
	// that one having run: any --filter matching some of these tests but not
	// the first left them asserting "new installation" against a populated data
	// dir. Doing it here means the file's precondition holds however the tests
	// are selected.
	recursiveDelete(cmsDataDir(), [], true);
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	// FirstLoginChecker caches its answer, and the data dir was just emptied
	// underneath it.
	$this->app->getContainer()->get(CacheManager::class)->clearAllCaches();
});

afterAll(function (): void {
	// Restore access control test data after fresh installation tests complete
	// This ensures subsequent access control tests have the required test users
	// cmsDataDir(), not tests/tcms-data: under --parallel each worker has its
	// own data dir, and this used to delete the worker's copy and put the
	// fixtures back in the shared one — leaving every file scheduled after this
	// one in the same worker with an empty data dir. The fixtures themselves
	// are shared and read-only.
	$testDataPath = rtrim(cmsDataDir(), '/');
	$fixturesPath = __DIR__ . '/../tcms-data-fixtures';

	// Restore auth directory
	$authSource = $fixturesPath . '/auth';
	$authDest   = $testDataPath . '/auth';
	if (is_dir($authSource)) {
		if (is_dir($authDest)) {
			recursiveDelete($authDest, [], true);
		}
		exec('cp -r ' . escapeshellarg($authSource) . ' ' . escapeshellarg($authDest));
	}

	// Restore .system/access-groups.json
	$accessGroupsSource = $fixturesPath . '/.system/access-groups.json';
	$accessGroupsDest   = $testDataPath . '/.system/access-groups.json';
	if (file_exists($accessGroupsSource)) {
		if (!is_dir(dirname($accessGroupsDest))) {
			mkdir(dirname($accessGroupsDest), 0755, true);
		}
		copy($accessGroupsSource, $accessGroupsDest);
	}
});

describe('First Time Login Workflow', function (): void {
	it('detects new installation correctly when no users exist', function (): void {
		$firstLoginChecker = $this->app->getContainer()->get(FirstLoginChecker::class);

		// On a fresh installation, should detect as new
		expect($firstLoginChecker->isNewInstallation())->toBeTrue();
	});

	it('automatically creates auth collection when checking for new installation', function (): void {
		$container         = $this->app->getContainer();
		$collectionFetcher = $container->get(CollectionFetcher::class);
		$firstLoginChecker = $container->get(FirstLoginChecker::class);
		$config            = $container->get(TotalCMS\Support\Config::class);
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
		$indexReader  = $container->get(IndexReader::class);
		$config       = $container->get(TotalCMS\Support\Config::class);

		$email    = 'admin@test.com';
		$password = 'secure-password-123';

		// Perform first login - should create user
		$user = $loginService->authenticate($email, $password);

		// Verify user was created with correct data. The id is assigned by the
		// auth schema's "${oid}" autogen setting (not hardcoded), so assert its
		// shape and cross-check it against the index rather than a fixed value.
		expect($user)->toBeArray();
		expect($user['id'])->toBeString();
		expect((string)$user['id'])->not()->toBe('');
		expect($user['name'])->toBe('Admin');
		expect($user['email'])->toBe($email);
		expect($user['active'])->toBe(true);
		expect($user['groups'])->toContain(UserValidationService::ADMINGROUP);
		expect(password_verify($password, (string)$user['password']))->toBeTrue();

		// Confirm the authenticated user's id matches the single object the
		// index recorded for the freshly-created auth collection.
		$index = $indexReader->fetchIndex($config->auth['collection']);
		expect($index->objects->count())->toBe(1);
		$indexedIds = $index->objects->map(static fn (array $object) => (string)$object['id'])->values()->all();
		expect($indexedIds)->toBe([(string)$user['id']]);
	});

	it('does not create duplicate users on subsequent logins', function (): void {
		$container         = $this->app->getContainer();
		$loginService      = $container->get(LoginService::class);
		$firstLoginChecker = $container->get(FirstLoginChecker::class);
		$indexReader       = $container->get(IndexReader::class);
		$config            = $container->get(TotalCMS\Support\Config::class);
		$authCollection    = $config->auth['collection'];

		$email    = 'admin@test.com';
		$password = 'secure-password-123';

		// These tests are stages of one workflow and normally run in order, so
		// the user already exists by now. State it rather than assume it: a
		// filtered run can start at this test, and "assert 1 user" would then
		// fail for a reason that has nothing to do with duplicate creation.
		//
		// isNewInstallation() first — it is what creates the auth collection on
		// a fresh install, and without it there is no index to count.
		$firstLoginChecker->isNewInstallation();
		if ($indexReader->fetchIndex($authCollection)->objects->count() === 0) {
			$loginService->authenticate($email, $password);
		}

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
