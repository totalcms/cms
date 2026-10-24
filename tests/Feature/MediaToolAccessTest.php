<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;

/**
 * The ImageWorks and file-links tools open in a dialog from an image or file
 * field, which can sit on a front-end form edited by a member of any auth
 * collection. They are gated by the object they describe: the user must be
 * able to read it. Before, they sat behind the admin area's
 * operator-collection gate, which refused every other auth collection and let
 * any operator open the tool for any collection whatever their groups.
 *
 * `members` is a second auth collection; its user carries the `blogger`
 * group, which reaches the `blog` collection only.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	signInAs($this->app, 'admin-user-test-com', 'auth');
	postJson('/api/collections', ['id' => 'blog', 'schema' => 'blog', 'name' => 'Blog'])->assertOk();
	postJson('/api/collections/blog', ['id' => 'hello', 'title' => 'Hello'])->assertOk();
	postJson('/api/collections', ['id' => 'members', 'schema' => 'auth', 'name' => 'Members'])->assertOk();
	postJson('/api/collections/members', [
		'id'       => 'pat',
		'name'     => 'Pat',
		'email'    => 'pat@test.com',
		'password' => 'Correct-Horse-Battery-9',
		'groups'   => ['blogger'],
		'active'   => true,
	])->assertOk();
});

dataset('media tools', [
	'imageworks' => ['/admin/imageworks', 'image'],
	'filelinks'  => ['/admin/filelinks', 'file'],
]);

it('opens for a member of another auth collection who can read the object', function (string $path, string $property): void {
	signInAs($this->app, 'pat', 'members');

	get("$path?collection=blog&id=hello&property=$property")->assertOk();
})->with('media tools');

it('refuses that member for a collection their groups do not reach', function (string $path, string $property): void {
	postJson('/api/collections', ['id' => 'secret', 'schema' => 'blog', 'name' => 'Secret'])->assertOk();
	signInAs($this->app, 'pat', 'members');

	get("$path?collection=secret&id=hello&property=$property")->assertStatus(403);
})->with('media tools');

it('refuses the tool default collection to that member too', function (string $path): void {
	signInAs($this->app, 'pat', 'members');

	// No ?collection= falls back to the template's default (image / file).
	get("$path?id=hello")->assertStatus(403);
})->with('media tools');

it('now holds an operator to their groups as well', function (string $path, string $property): void {
	postJson('/api/collections', ['id' => 'secret', 'schema' => 'blog', 'name' => 'Secret'])->assertOk();
	signInAs($this->app, 'blogger-user-test-com', '');

	get("$path?collection=blog&id=hello&property=$property")->assertOk();
	get("$path?collection=secret&id=hello&property=$property")->assertStatus(403);
})->with('media tools');

it('still opens for an admin', function (string $path, string $property): void {
	get("$path?collection=blog&id=hello&property=$property")->assertOk();
})->with('media tools');
