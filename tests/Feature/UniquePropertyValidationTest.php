<?php

use function Nekofar\Slim\Pest\deleteJson;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;
use function Nekofar\Slim\Pest\putJson;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('Unique Property Validation', function (): void {
	beforeEach(function (): void {
		// Create a test schema with unique email field
		$schema = [
			'id'          => 'unique-test',
			'type'        => 'object',
			'description' => 'Test schema for unique validation',
			'properties'  => [
				'id'    => [
					'type'  => 'string',
					'label' => 'ID',
					'field' => 'input',
				],
				'name'  => [
					'type'  => 'string',
					'label' => 'Name',
					'field' => 'input',
				],
				'email' => [
					'type'   => 'email',
					'label'  => 'Email',
					'field'  => 'email',
					'unique' => true,
				],
			],
			'required'    => ['id', 'name'],
			'index'       => ['id', 'name', 'email'],
		];

		postJson('/api/schemas', $schema)->assertOk();

		// Create collection using this schema
		$collection = [
			'id'          => 'test-unique',
			'schema'      => 'unique-test',
			'name'        => 'Test Unique Collection',
			'description' => 'Collection for testing unique validation',
			'url'         => '',
			'properties'  => [
				'id'    => [
					'label' => 'ID',
					'field' => 'id',
				],
				'name'  => [
					'label' => 'Name',
					'field' => 'input',
				],
				'email' => [
					'label' => 'Email',
					'field' => 'email',
				],
			],
		];

		postJson('/api/collections', $collection)->assertOk();
	});

	afterEach(function (): void {
		// Clean up test schema and collection
		if (file_exists(schemaPath('unique-test'))) {
			unlink(schemaPath('unique-test'));
		}
		if (file_exists(collectionPath('test-unique') . '.meta.json')) {
			unlink(collectionPath('test-unique') . '.meta.json');
		}
		// Clean up any test objects
		$collectionDir = collectionPath('test-unique');
		if (is_dir($collectionDir)) {
			recursiveDelete($collectionDir);
		}
	});

	test('it allows saving object with unique email', function (): void {
		$member1 = [
			'id'    => 'member-1',
			'name'  => 'John Doe',
			'email' => 'john@example.com',
		];

		postJson('/api/collections/test-unique', $member1)
			->assertOk()
			->assertJsonFragment(['email' => 'john@example.com']);
	});

	test('it rejects duplicate email with proper error message', function (): void {
		// Save first member
		postJson('/api/collections/test-unique', [
			'id'    => 'member-1',
			'name'  => 'John Doe',
			'email' => 'john@example.com',
		])->assertOk();

		// Try to save second member with same email
		$member2 = [
			'id'    => 'member-2',
			'name'  => 'Jane Smith',
			'email' => 'john@example.com', // Duplicate
		];

		postJson('/api/collections/test-unique', $member2)
			->assertBadRequest()
			->assertSee('Email must be unique')
			->assertSee('john@example.com')
			->assertSee('already exists');
	});

	test('it allows updating object keeping same email', function (): void {
		// Save initial object
		postJson('/api/collections/test-unique', [
			'id'    => 'member-1',
			'name'  => 'John Doe',
			'email' => 'john@example.com',
		])->assertOk();

		// Update member-1 with same email (should succeed)
		$updated = [
			'id'    => 'member-1',
			'name'  => 'John Updated',
			'email' => 'john@example.com', // Same email
		];

		putJson('/api/collections/test-unique/member-1', $updated)
			->assertOk()
			->assertJsonFragment(['name' => 'John Updated']);
	});

	test('it allows updating object to new unique email', function (): void {
		// Save initial object
		postJson('/api/collections/test-unique', [
			'id'    => 'member-1',
			'name'  => 'John Doe',
			'email' => 'john@example.com',
		])->assertOk();

		// Update member-1 to new email
		$updated = [
			'id'    => 'member-1',
			'name'  => 'John Doe',
			'email' => 'john.doe@example.com',
		];

		putJson('/api/collections/test-unique/member-1', $updated)
			->assertOk()
			->assertJsonFragment(['email' => 'john.doe@example.com']);
	});

	test('it rejects updating object to duplicate email', function (): void {
		// Create first member
		postJson('/api/collections/test-unique', [
			'id'    => 'member-1',
			'name'  => 'John Doe',
			'email' => 'john@example.com',
		])->assertOk();

		// Create second member
		$member2 = [
			'id'    => 'member-2',
			'name'  => 'Bob Jones',
			'email' => 'bob@example.com',
		];

		postJson('/api/collections/test-unique', $member2)->assertOk();

		// Try to update member-2 to john's email (duplicate)
		$updated = [
			'id'    => 'member-2',
			'name'  => 'Bob Jones',
			'email' => 'john@example.com', // Duplicate
		];

		putJson('/api/collections/test-unique/member-2', $updated)
			->assertBadRequest()
			->assertSee('Email must be unique')
			->assertSee('john@example.com');
	});

	test('it allows multiple objects with empty unique values', function (): void {
		// Create member without email
		$member1 = [
			'id'    => 'member-1',
			'name'  => 'Alice Wonder',
			'email' => '',
		];

		postJson('/api/collections/test-unique', $member1)->assertOk();

		// Create another member without email (should be allowed)
		$member2 = [
			'id'    => 'member-2',
			'name'  => 'Charlie Brown',
			'email' => '',
		];

		postJson('/api/collections/test-unique', $member2)->assertOk();

		// Verify both exist
		get('/api/collections/test-unique/member-1')->assertOk();
		get('/api/collections/test-unique/member-2')->assertOk();
	});

	test('it provides helpful error when unique property not in index', function (): void {
		// Create a test schema with unique field not in index
		$schema = [
			'id'          => 'test-unique-index',
			'type'        => 'object',
			'description' => 'Test schema with unique field not in index',
			'properties'  => [
				'id'       => ['type' => 'string', 'label' => 'ID', 'field' => 'input'],
				'username' => [
					'type'   => 'string',
					'label'  => 'Username',
					'field'  => 'input',
					'unique' => true,
				],
			],
			'required'    => ['id', 'username'],
			'index'       => ['id'], // username not in index!
		];

		postJson('/api/schemas', $schema)->assertOk();

		// Create collection using this schema
		$collection = [
			'id'          => 'test-unique-index',
			'schema'      => 'test-unique-index',
			'name'        => 'Test Unique Index',
			'description' => '',
			'url'         => '',
			'properties'  => [
				'id'       => ['label' => 'ID', 'field' => 'id'],
				'username' => ['label' => 'Username', 'field' => 'input'],
			],
		];

		postJson('/api/collections', $collection)->assertOk();

		// Try to save object
		$object = [
			'id'       => 'test-1',
			'username' => 'testuser',
		];

		postJson('/api/collections/test-unique-index', $object)
			->assertBadRequest()
			->assertSee('unique')
			->assertSee('index');

		// Cleanup
		if (file_exists(collectionPath('test-unique-index') . '.meta.json')) {
			unlink(collectionPath('test-unique-index') . '.meta.json');
		}
		$collectionDir = collectionPath('test-unique-index');
		if (is_dir($collectionDir)) {
			recursiveDelete($collectionDir);
		}
		if (file_exists(schemaPath('test-unique-index'))) {
			unlink(schemaPath('test-unique-index'));
		}
	});

	test('it validates uniqueness case-sensitively', function (): void {
		// Create member with lowercase email
		$member1 = [
			'id'    => 'member-1',
			'name'  => 'Test User',
			'email' => 'test@example.com',
		];

		postJson('/api/collections/test-unique', $member1)->assertOk();

		// Try exact duplicate (should be rejected)
		$member2 = [
			'id'    => 'member-2',
			'name'  => 'Another User',
			'email' => 'test@example.com', // Exact duplicate
		];

		postJson('/api/collections/test-unique', $member2)
			->assertBadRequest()
			->assertSee('Email must be unique');

		// Try uppercase version (currently allowed - case-sensitive matching)
		// Note: Ideally emails should be normalized to lowercase before unique check
		// since email addresses are case-insensitive per RFC standards
		$member3 = [
			'id'    => 'member-3',
			'name'  => 'Third User',
			'email' => 'TEST@example.com',
		];

		postJson('/api/collections/test-unique', $member3)->assertOk();
	});

	test('it handles unique validation after deleting objects', function (): void {
		// Create initial member
		postJson('/api/collections/test-unique', [
			'id'    => 'member-1',
			'name'  => 'John Doe',
			'email' => 'john@example.com',
		])->assertOk();

		// Delete member-1
		deleteJson('/api/collections/test-unique/member-1')->assertOk();

		// Should now be able to reuse the email
		$member2 = [
			'id'    => 'member-2',
			'name'  => 'New John',
			'email' => 'john@example.com',
		];

		postJson('/api/collections/test-unique', $member2)->assertOk();
	});

	test('it validates multiple unique properties independently', function (): void {
		// Create schema with multiple unique fields
		$schema = [
			'id'          => 'test-multi-unique',
			'type'        => 'object',
			'description' => 'Test schema with multiple unique fields',
			'properties'  => [
				'id'       => ['type' => 'string', 'label' => 'ID', 'field' => 'input'],
				'email'    => [
					'type'   => 'email',
					'label'  => 'Email',
					'field'  => 'email',
					'unique' => true,
				],
				'username' => [
					'type'   => 'string',
					'label'  => 'Username',
					'field'  => 'input',
					'unique' => true,
				],
			],
			'required'    => ['id', 'email', 'username'],
			'index'       => ['id', 'email', 'username'],
		];

		postJson('/api/schemas', $schema)->assertOk();

		// Create collection using this schema
		$collection = [
			'id'          => 'test-multi-unique',
			'schema'      => 'test-multi-unique',
			'name'        => 'Test Multi Unique',
			'description' => '',
			'url'         => '',
			'properties'  => [
				'id'       => ['label' => 'ID', 'field' => 'id'],
				'email'    => ['label' => 'Email', 'field' => 'email'],
				'username' => ['label' => 'Username', 'field' => 'input'],
			],
		];

		postJson('/api/collections', $collection)->assertOk();

		// Save first object
		$obj1 = [
			'id'       => 'obj-1',
			'email'    => 'user1@test.com',
			'username' => 'user1',
		];

		postJson('/api/collections/test-multi-unique', $obj1)->assertOk();

		// Try duplicate email (should fail)
		$obj2 = [
			'id'       => 'obj-2',
			'email'    => 'user1@test.com', // Duplicate
			'username' => 'user2', // Unique
		];

		postJson('/api/collections/test-multi-unique', $obj2)
			->assertBadRequest()
			->assertSee('Email');

		// Try duplicate username (should fail)
		$obj3 = [
			'id'       => 'obj-3',
			'email'    => 'user2@test.com', // Unique
			'username' => 'user1', // Duplicate
		];

		postJson('/api/collections/test-multi-unique', $obj3)
			->assertBadRequest()
			->assertSee('Username');

		// Both unique (should succeed)
		$obj4 = [
			'id'       => 'obj-4',
			'email'    => 'user2@test.com',
			'username' => 'user2',
		];

		postJson('/api/collections/test-multi-unique', $obj4)->assertOk();

		// Cleanup
		if (file_exists(collectionPath('test-multi-unique') . '.meta.json')) {
			unlink(collectionPath('test-multi-unique') . '.meta.json');
		}
		$collectionDir = collectionPath('test-multi-unique');
		if (is_dir($collectionDir)) {
			recursiveDelete($collectionDir);
		}
		if (file_exists(schemaPath('test-multi-unique'))) {
			unlink(schemaPath('test-multi-unique'));
		}
	});
});
