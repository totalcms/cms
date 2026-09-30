<?php

declare(strict_types=1);

use Slim\App;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Repository\AccessGroupRepository;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;

/**
 * The bulk mailer endpoints are admin tools. They used to carry only the Pro
 * edition gate, so anyone could queue a send to a whole collection or list
 * any collection's object ids through /bulk/objects. They now need a signed-in
 * user (or API key) whose access group grants the mailer permission.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

function bulkMailerSetGroupMailer(App $app, bool $allowed): void
{
	$repo  = $app->getContainer()->get(AccessGroupRepository::class);
	$group = $repo->findById('blogger');
	expect($group)->not->toBeNull();

	$permissions           = $group->permissions;
	$permissions['mailer'] = $allowed;
	$repo->save(new AccessGroupData([
		'id'          => 'blogger',
		'description' => $group->description,
		'operations'  => $group->operations,
		'permissions' => $permissions,
	]));
}

it('refuses every bulk endpoint to an anonymous caller', function (): void {
	$config         = $this->app->getContainer()->get(Config::class);
	$auth           = $config->auth;
	$auth['enable'] = true;
	$config->auth   = $auth;

	foreach ([
		get('/api/action/mailer/bulk/history?mailerId=news'),
		get('/api/action/mailer/bulk/objects?bulkCollection=blog'),
		post('/api/action/mailer/bulk', ['mailerId' => 'news', 'bulkCollection' => 'blog']),
		post('/api/action/mailer/bulk/preview', ['mailerId' => 'news', 'bulkPreviewObjectId' => 'x', 'bulkCollection' => 'blog']),
	] as $response) {
		expect($response->getStatusCode())->toBeIn([401, 403]);
	}
});

it('refuses a signed-in user whose group lacks the mailer permission', function (): void {
	bulkMailerSetGroupMailer($this->app, false);
	signInAs($this->app, 'blogger-user-test-com', 'auth');

	get('/api/action/mailer/bulk/history?mailerId=news')->assertForbidden();
});

it('serves a signed-in user whose group has the mailer permission', function (): void {
	bulkMailerSetGroupMailer($this->app, true);
	signInAs($this->app, 'blogger-user-test-com', 'auth');

	$response = get('/api/action/mailer/bulk/history?mailerId=news');

	$response->assertOk();
	expect((string)$response->getBody())->toContain('has not been bulk sent yet');
});
