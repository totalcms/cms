<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\getJson;
use function TotalCMS\Slim\Pest\patchJson;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\putJson;

/**
 * Access control asserted over HTTP with authentication actually ON.
 *
 * tests/Feature/AccessMiddlewareTest.php looks like it covers this ground but
 * cannot: the suite disables auth, so every access middleware short-circuits
 * and that file's `toBeIn([200, 302])` assertions pass no matter what the
 * authorization layer does. These tests use signInAs(), which turns auth on
 * for the one app under test.
 *
 * `blogger` grants collections.allowed = ['blog'] and nothing else, so this
 * user has no rights at all on the `auth` collection — the shape of an
 * "Editor" who can only reach the blog.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

/** The fixture record, with one ordinary field changed. */
function bloggerRecordWith(array $changes): array
{
	$record = json_decode((string)file_get_contents(cmsDataDir() . 'auth/blogger-user-test-com.json'), true);

	return array_merge((array)$record, $changes);
}

describe('self-profile writes', function (): void {
	// The regression. AUTH_COLLECTION is empty for anyone who signed in at the
	// plain /admin/login route, and the carve-out used to compare it literally
	// against the route's collection — so a user could not save their own
	// profile and got "Access denied ... on this collection".
	it('lets a user PUT their own record when AUTH_COLLECTION is empty', function (): void {
		signInAs($this->app, 'blogger-user-test-com', '');

		putJson('/api/collections/auth/blogger-user-test-com', bloggerRecordWith(['name' => 'Renamed By Self']))
			->assertOk();
	});

	it('lets a user PUT their own record when AUTH_COLLECTION is set', function (): void {
		signInAs($this->app, 'blogger-user-test-com', 'auth');

		putJson('/api/collections/auth/blogger-user-test-com', bloggerRecordWith(['name' => 'Renamed By Self']))
			->assertOk();
	});

	it('lets a user PATCH their own record', function (): void {
		signInAs($this->app, 'blogger-user-test-com', '');

		patchJson('/api/collections/auth/blogger-user-test-com', ['name' => 'Patched By Self'])
			->assertOk();
	});

	it('refuses a write to another users record', function (): void {
		signInAs($this->app, 'blogger-user-test-com', '');

		patchJson('/api/collections/auth/admin-user-test-com', ['name' => 'Hijacked'])
			->assertStatus(403);
	});
});

describe('privileged fields on a self-profile write', function (): void {
	it('neutralizes a self-assigned group without failing the save', function (): void {
		signInAs($this->app, 'blogger-user-test-com', '');

		patchJson('/api/collections/auth/blogger-user-test-com', [
			'name'   => 'Still Saved',
			'groups' => ['admin'],
		])->assertOk();

		$stored = json_decode((string)file_get_contents(cmsDataDir() . 'auth/blogger-user-test-com.json'), true);

		expect($stored['groups'])->toBe(['blogger']);
		expect($stored['name'])->toBe('Still Saved');
	});

	// maxLoginCount is enforced as `loginCount >= maxLoginCount`, so a writable
	// counter makes the ceiling meaningless.
	it('refuses to let a user rewind their own login counter', function (): void {
		signInAs($this->app, 'blogger-user-test-com', '');

		patchJson('/api/collections/auth/blogger-user-test-com', ['loginCount' => 0])->assertOk();

		$stored = json_decode((string)file_get_contents(cmsDataDir() . 'auth/blogger-user-test-com.json'), true);

		expect($stored['loginCount'])->toBe(2);
	});
});

describe('ordinary collection access still applies', function (): void {
	// Proves the harness is not simply allowing (or denying) everything: the
	// same signed-in user is granted one collection and refused another.
	beforeEach(function (): void {
		// `blogger` grants collections.allowed = ['blog'], so seed that
		// collection as an admin before dropping to the restricted user.
		signInAs($this->app, 'admin-user-test-com', 'auth');
		postJson('/api/collections', [
			'id'         => 'blog',
			'schema'     => 'text',
			'name'       => 'Blog',
			'url'        => '',
			'properties' => [
				'id'    => ['label' => 'ID', 'field' => 'id'],
				'title' => ['label' => 'Title', 'field' => 'input'],
			],
		])->assertOk();
	});

	it('allows a granted collection', function (): void {
		signInAs($this->app, 'blogger-user-test-com', '');

		getJson('/api/collections/blog')->assertOk();
	});

	it('refuses a collection the group does not grant', function (): void {
		signInAs($this->app, 'blogger-user-test-com', '');

		getJson('/api/collections/auth')->assertStatus(403);
	});
});
