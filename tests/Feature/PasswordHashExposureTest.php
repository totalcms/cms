<?php

declare(strict_types=1);

use Slim\App;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\getJson;
use function TotalCMS\Slim\Pest\patchJson;
use function TotalCMS\Slim\Pest\putJson;

/**
 * Stored password hashes never leave the server.
 *
 * A customer found that with public Read on the auth collection, anyone could
 * GET /api/collections/auth/{id} and receive the user's bcrypt hash. Read
 * permissions decide who sees a record; no permission setting may expose the
 * hash itself. Login and password reset read the hash internally and are
 * unaffected.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

/** Turn authentication on for the app under test without signing anyone in. */
function enableAuthAnonymously(App $app): void
{
	/** @var Config $config */
	$config         = $app->getContainer()->get(Config::class);
	$auth           = $config->auth;
	$auth['enable'] = true;
	$config->auth   = $auth;
}

function storedPasswordHash(string $id): string
{
	$record = json_decode((string)file_get_contents(cmsDataDir() . "auth/$id.json"), true);

	return (string)($record['password'] ?? '');
}

describe('anonymous read of a publicly readable auth collection', function (): void {
	it('is refused while public Read is off', function (): void {
		enableAuthAnonymously($this->app);

		getJson('/api/collections/auth/blogger-user-test-com')->assertStatus(401);
	});

	it('returns the record without the password hash once public Read is on', function (): void {
		// Auth is still off here, so this configures the collection the way the
		// customer's sites were configured.
		patchJson('/api/collections/auth', ['publicOperations' => ['read']])->assertOk();
		enableAuthAnonymously($this->app);

		$response = getJson('/api/collections/auth/blogger-user-test-com')->assertOk();
		$data     = json_decode((string)$response->getBody(), true)['data'];

		expect($data['id'])->toBe('blogger-user-test-com')
			->and($data)->not->toHaveKey('password')
			->and((string)$response->getBody())->not->toContain('$2y$');
	});
});

it('leaves the password hash out of an object fetched by an admin', function (): void {
	signInAs($this->app, 'admin', '');

	$response = getJson('/api/collections/auth/blogger-user-test-com')->assertOk();

	expect(json_decode((string)$response->getBody(), true)['data'])->not->toHaveKey('password');
});

it('keeps password hashes out of an index entry appended on save', function (): void {
	$container = $this->app->getContainer();
	$object    = $container->get(ObjectFetcher::class)->fetchObject('auth', 'blogger-user-test-com');
	$builder   = $container->get(IndexBuilder::class);

	$builder->appendObjectToIndex('auth', $object);

	$index = (string)file_get_contents(cmsDataDir() . 'auth/.index.json');
	expect($index)->toContain('blogger-user-test-com')
		->and($index)->not->toContain('$2y$');
});

describe('a full-object PUT that omits the password', function (): void {
	it('keeps the stored hash', function (): void {
		$before = storedPasswordHash('blogger-user-test-com');
		expect($before)->toStartWith('$2y$');

		// What a client that fetched the record and sent it back now does.
		$record = json_decode((string)file_get_contents(cmsDataDir() . 'auth/blogger-user-test-com.json'), true);
		unset($record['password']);
		$record['name'] = 'Round Tripped';

		putJson('/api/collections/auth/blogger-user-test-com', $record)->assertOk();

		expect(storedPasswordHash('blogger-user-test-com'))->toBe($before);
	});

	it('still sets a password the caller sends', function (): void {
		$before = storedPasswordHash('blogger-user-test-com');

		$record             = json_decode((string)file_get_contents(cmsDataDir() . 'auth/blogger-user-test-com.json'), true);
		$record['password'] = 'a-brand-new-password';

		putJson('/api/collections/auth/blogger-user-test-com', $record)->assertOk();

		$after = storedPasswordHash('blogger-user-test-com');
		expect($after)->not->toBe($before)
			->and(password_verify('a-brand-new-password', $after))->toBeTrue();
	});
});
