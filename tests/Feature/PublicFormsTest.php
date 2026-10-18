<?php

use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\BotProtection;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\delete;
use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\head;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\putJson;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

afterEach(function (): void {
	// Clean up test data
	recursiveDelete(cmsDataDir());
});

it('allows public create operation', function (): void {
	// Create collection with public create
	$collectionData = json_decode(file_get_contents(testData('public-collection.json')), true);
	postJson('/api/collections', $collectionData);

	$data = [
		'id'   => 'test-public-create',
		'text' => 'Public submission',
	];

	// Make request without authentication
	$response = postJson('/api/collections/public-collection', $data);

	// Should succeed
	expect($response->getStatusCode())->toBeIn([200, 201]);

	// Verify object was created
	$result = json_decode((string)$response->getBody(), true);
	expect($result['data'])->toHaveKey('id', 'test-public-create');
	expect($result['data'])->toHaveKey('text', 'Public submission');
});

it('allows public read operation', function (): void {
	// Create collection with public read
	$collectionData = json_decode(file_get_contents(testData('public-collection.json')), true);
	postJson('/api/collections', $collectionData);

	// Create an object first
	$objectData = ['id' => 'test-read', 'text' => 'Test content'];
	postJson('/api/collections/public-collection', $objectData);

	// Try to read without authentication
	$response = get('/api/collections/public-collection/test-read');

	// Should succeed
	expect($response->getStatusCode())->toBe(200);

	$result = json_decode((string)$response->getBody(), true);
	expect($result['data'])->toHaveKey('id', 'test-read');
});

it('rejects operations not in publicOperations array', function (): void {
	// Create collection with only create and read
	$collectionData = json_decode(file_get_contents(testData('public-collection.json')), true);
	postJson('/api/collections', $collectionData);

	// Create an object first
	$objectData = ['id' => 'test-update', 'text' => 'Original'];
	postJson('/api/collections/public-collection', $objectData);

	// Try to update without authentication (update not in publicOperations)
	$updateData = ['text' => 'Updated'];
	$response   = putJson('/api/collections/public-collection/test-update', $updateData);

	// Should fail - update not allowed publicly
	expect($response->getStatusCode())->toBeIn([302, 401, 403]);
})->skip('Test framework maintains auth state');

it('allows public update when configured', function (): void {
	// Create collection with public update
	$collectionData = [
		'id'               => 'public-update-test',
		'schema'           => 'text',
		'name'             => 'Public Update Test',
		'publicOperations' => ['create', 'read', 'update'],
		'properties'       => [
			'id'   => ['label' => 'ID', 'field' => 'id'],
			'text' => ['label' => 'Text', 'field' => 'textarea'],
		],
	];
	postJson('/api/collections', $collectionData);

	// Create object
	$objectData = ['id' => 'test-update', 'text' => 'Original'];
	postJson('/api/collections/public-update-test', $objectData);

	// Update without authentication (PUT requires full object including ID)
	$updateData = ['id' => 'test-update', 'text' => 'Updated text'];
	$response   = putJson('/api/collections/public-update-test/test-update', $updateData);

	// Should succeed
	expect($response->getStatusCode())->toBeIn([200, 201]);
});

it('allows public delete when configured', function (): void {
	// Create collection with public delete
	$collectionData = [
		'id'               => 'public-delete-test',
		'schema'           => 'text',
		'name'             => 'Public Delete Test',
		'publicOperations' => ['create', 'read', 'delete'],
		'properties'       => [
			'id'   => ['label' => 'ID', 'field' => 'id'],
			'text' => ['label' => 'Text', 'field' => 'textarea'],
		],
	];
	postJson('/api/collections', $collectionData);

	// Create object
	$objectData = ['id' => 'test-delete', 'text' => 'Will be deleted'];
	postJson('/api/collections/public-delete-test', $objectData);

	// Delete without authentication
	$response = delete('/api/collections/public-delete-test/test-delete');

	// Should succeed
	expect($response->getStatusCode())->toBeIn([200, 204]);
});

it('normalizes operation names to lowercase', function (): void {
	// Create collection with uppercase operations
	$collectionData = [
		'id'               => 'case-test',
		'schema'           => 'text',
		'name'             => 'Case Test',
		'publicOperations' => ['CREATE', 'Read', 'UPDATE'],
		'properties'       => [
			'id'   => ['label' => 'ID', 'field' => 'id'],
			'text' => ['label' => 'Text', 'field' => 'textarea'],
		],
	];
	postJson('/api/collections', $collectionData);

	// Should work - operations normalized to lowercase
	$objectData = ['id' => 'test-case', 'text' => 'Test'];
	$response   = postJson('/api/collections/case-test', $objectData);

	expect($response->getStatusCode())->toBeIn([200, 201]);
});

it('ignores invalid operation names', function (): void {
	// Create collection with invalid operations
	$collectionData = [
		'id'               => 'invalid-ops-test',
		'schema'           => 'text',
		'name'             => 'Invalid Ops Test',
		'publicOperations' => ['create', 'hack', 'destroy', 'read'],
		'properties'       => [
			'id'   => ['label' => 'ID', 'field' => 'id'],
			'text' => ['label' => 'Text', 'field' => 'textarea'],
		],
	];
	postJson('/api/collections', $collectionData);

	// Valid operations should work
	$objectData = ['id' => 'test', 'text' => 'Test'];
	$response   = postJson('/api/collections/invalid-ops-test', $objectData);

	expect($response->getStatusCode())->toBeIn([200, 201]);
});

it('always allows HEAD requests for object existence checking', function (): void {
	// Create collection with NO public operations
	$collectionData = [
		'id'               => 'private-head-test',
		'schema'           => 'text',
		'name'             => 'Private HEAD Test',
		'publicOperations' => [], // No public operations
		'properties'       => [
			'id'   => ['label' => 'ID', 'field' => 'id'],
			'text' => ['label' => 'Text', 'field' => 'textarea'],
		],
	];
	postJson('/api/collections', $collectionData);

	// Create object (authenticated)
	$objectData = ['id' => 'test-exists', 'text' => 'Test'];
	postJson('/api/collections/private-head-test', $objectData);

	// HEAD request should work even though read is not in publicOperations
	$response = \TotalCMS\Slim\Pest\head('/api/collections/private-head-test/test-exists');

	// Should succeed - HEAD always allowed
	expect($response->getStatusCode())->toBe(200);

	// HEAD for non-existent object
	$response404 = \TotalCMS\Slim\Pest\head('/api/collections/private-head-test/does-not-exist');

	// Should return 404 (not auth error)
	expect($response404->getStatusCode())->toBe(404);
});

describe('bot protection on public creates', function (): void {
	// A public-create collection, then authentication on with nobody signed in
	// — the suite runs with auth off, which skips the public-submission path.
	$setUp = function (bool $require = false): void {
		$collection                         = json_decode(file_get_contents(testData('public-collection.json')), true);
		$collection['requireBotProtection'] = $require;
		postJson('/api/collections', $collection)->assertOk();

		/** @var Config $config */
		$config         = $this->app->getContainer()->get(Config::class);
		$auth           = $config->auth;
		$auth['enable'] = true;
		$config->auth   = $auth;
	};

	$token  = static fn (int $issuedAt): string => (new BotProtection())->issue('public-collection', 'create', $issuedAt);
	$stored = static fn (string $id): bool => is_file(cmsDataDir() . "public-collection/{$id}.json");

	it('saves a submission with a valid token, without the bot fields', function () use ($setUp, $token, $stored): void {
		$setUp->call($this);

		$response = postJson('/api/collections/public-collection', [
			'id'                           => 'human',
			'text'                         => 'Hello',
			BotProtection::HONEYPOT_FIELD  => '',
			BotProtection::TIMESTAMP_FIELD => $token(time() - 10),
		]);

		expect($response->getStatusCode())->toBeIn([200, 201]);
		expect($stored('human'))->toBeTrue();
		$record = json_decode((string)file_get_contents(cmsDataDir() . 'public-collection/human.json'), true);
		expect($record)->not->toHaveKey(BotProtection::HONEYPOT_FIELD);
		expect($record)->not->toHaveKey(BotProtection::TIMESTAMP_FIELD);
	});

	it('answers a filled honeypot with a success it never saves', function () use ($setUp, $token, $stored): void {
		$setUp->call($this);

		$response = postJson('/api/collections/public-collection', [
			'id'                           => 'bot',
			'text'                         => 'Buy now',
			BotProtection::HONEYPOT_FIELD  => 'https://spam.example',
			BotProtection::TIMESTAMP_FIELD => $token(time() - 10),
		]);

		expect($response->getStatusCode())->toBeIn([200, 201]);
		$result = json_decode((string)$response->getBody(), true);
		expect($result['data'])->toHaveKey('id', 'bot');
		expect($result['data'])->toHaveKey('text', 'Buy now');
		expect($result['data'])->not->toHaveKey(BotProtection::HONEYPOT_FIELD);
		expect($stored('bot'))->toBeFalse();
	});

	it('answers a caught bot posting an existing id with the same error a real save gives', function () use ($setUp, $token, $stored): void {
		$setUp->call($this);

		$real = postJson('/api/collections/public-collection', [
			'id'                           => 'taken',
			'text'                         => 'First',
			BotProtection::TIMESTAMP_FIELD => $token(time() - 10),
		]);
		expect($real->getStatusCode())->toBeIn([200, 201]);
		expect($stored('taken'))->toBeTrue();

		$realDuplicate = postJson('/api/collections/public-collection', [
			'id'                           => 'taken',
			'text'                         => 'Again',
			BotProtection::TIMESTAMP_FIELD => $token(time() - 10),
		]);
		$botDuplicate = postJson('/api/collections/public-collection', [
			'id'                           => 'taken',
			'text'                         => 'Spam',
			BotProtection::HONEYPOT_FIELD  => 'https://spam.example',
			BotProtection::TIMESTAMP_FIELD => $token(time() - 10),
		]);

		expect($botDuplicate->getStatusCode())->toBe($realDuplicate->getStatusCode());
		expect($realDuplicate->getStatusCode())->toBeGreaterThanOrEqual(400);
	});

	it('answers a too-fast submission with a success it never saves', function () use ($setUp, $token, $stored): void {
		$setUp->call($this);

		$response = postJson('/api/collections/public-collection', [
			'id'                           => 'fast',
			'text'                         => 'Quick',
			BotProtection::HONEYPOT_FIELD  => '',
			BotProtection::TIMESTAMP_FIELD => $token(time()),
		]);

		expect($response->getStatusCode())->toBeIn([200, 201]);
		expect(json_decode((string)$response->getBody(), true)['data'])->toHaveKey('id', 'fast');
		expect($stored('fast'))->toBeFalse();
	});

	it('saves a submission without bot fields when the collection does not require them', function () use ($setUp, $stored): void {
		$setUp->call($this);

		$response = postJson('/api/collections/public-collection', ['id' => 'plain', 'text' => 'Hi']);

		expect($response->getStatusCode())->toBeIn([200, 201]);
		expect($stored('plain'))->toBeTrue();
	});

	it('never saves a submission without bot fields when the collection requires them', function () use ($setUp, $stored): void {
		$setUp->call($this, true);

		$response = postJson('/api/collections/public-collection', ['id' => 'direct', 'text' => 'Hi']);

		expect($response->getStatusCode())->toBeIn([200, 201]);
		expect(json_decode((string)$response->getBody(), true)['data'])->toHaveKey('id', 'direct');
		expect($stored('direct'))->toBeFalse();
	});

	it('tells a person whose form expired to reload, with a 422, and saves nothing', function () use ($setUp, $token, $stored): void {
		$setUp->call($this);

		$response = postJson('/api/collections/public-collection', [
			'id'                           => 'stale',
			'text'                         => 'Left open',
			BotProtection::HONEYPOT_FIELD  => '',
			BotProtection::TIMESTAMP_FIELD => $token(time() - BotProtection::MAX_AGE_SECONDS - 60),
		]);

		expect($response->getStatusCode())->toBe(422);
		expect(json_decode((string)$response->getBody(), true)['error']['message'])->toBe('This form has expired. Reload the page and try again.');
		expect($stored('stale'))->toBeFalse();
	});

	it('answers an anonymous clone with a decoy and copies nothing when the collection requires bot protection', function () use ($setUp, $stored): void {
		$setUp->call($this, true);
		// Seed the source with authentication still off for this write.
		$this->app->getContainer()->get(ObjectSaver::class)->saveObject('public-collection', ['id' => 'source', 'text' => 'Original']);

		$response = postJson('/api/collections/public-collection/source/clone', ['id' => 'copy']);

		expect($response->getStatusCode())->toBeIn([200, 201]);
		$result = json_decode((string)$response->getBody(), true);
		expect($result['data'])->toHaveKey('id', 'copy');
		expect($result['data'])->toHaveKey('text', 'Original');
		expect($stored('copy'))->toBeFalse();
	});

	it('still clones anonymously when the collection does not require bot protection', function () use ($setUp, $stored): void {
		$setUp->call($this);
		$this->app->getContainer()->get(ObjectSaver::class)->saveObject('public-collection', ['id' => 'source', 'text' => 'Original']);

		$response = postJson('/api/collections/public-collection/source/clone', ['id' => 'copy']);

		expect($response->getStatusCode())->toBeIn([200, 201]);
		expect($stored('copy'))->toBeTrue();
	});

	it('saves a signed-in submission without bot fields even when required', function () use ($setUp, $stored): void {
		$setUp->call($this, true);
		signInAs($this->app, 'admin-user-test-com');

		$response = postJson('/api/collections/public-collection', ['id' => 'member', 'text' => 'Hi']);

		expect($response->getStatusCode())->toBeIn([200, 201]);
		expect($stored('member'))->toBeTrue();
	});
});
