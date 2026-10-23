<?php

declare(strict_types=1);

use Slim\App;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Repository\AccessGroupRepository;

use function TotalCMS\Slim\Pest\get;

/**
 * The Log Analyzer download and the Activity Log export are files, served
 * from their own routes beside the utility pages. Their URLs name the page
 * (`/utils/{page:logs}/download`, `/utils/{page:activity}/export`) so
 * UtilsAccessMiddleware checks that page's grant. Without it the middleware
 * only asks "may this user open any utility", and a group granted the Cache
 * Manager could download every log file.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

/** Give the fixture `blogger` group exactly these utility pages. */
function utilsDownloadGrant(App $app, array $allowed): void
{
	$repo  = $app->getContainer()->get(AccessGroupRepository::class);
	$group = $repo->findById('blogger');
	expect($group)->not->toBeNull();

	$permissions          = $group->permissions;
	$permissions['utils'] = ['all' => false, 'allowed' => $allowed];
	$repo->save(new AccessGroupData([
		'id'          => 'blogger',
		'description' => $group->description,
		'operations'  => $group->operations,
		'permissions' => $permissions,
	]));

	signInAs($app, 'blogger-user-test-com', 'auth');
}

it('refuses both downloads to a group granted only another utility', function (): void {
	utilsDownloadGrant($this->app, ['cache']);

	get('/admin/utils/logs/download?logfile=totalcms.log')->assertForbidden();
	get('/admin/utils/activity/export')->assertForbidden();
});

it('lets the Log Analyzer grant download logs, but not export activity', function (): void {
	utilsDownloadGrant($this->app, ['log-analyzer']);

	// Past the access check: an unknown log file is the action's own 404.
	expect(get('/admin/utils/logs/download?logfile=no-such.log')->getStatusCode())->toBe(404);
	get('/admin/utils/activity/export')->assertForbidden();
});

it('lets the Activity Log grant export activity, but not download logs', function (): void {
	utilsDownloadGrant($this->app, ['activity']);

	get('/admin/utils/activity/export')->assertOk();
	get('/admin/utils/logs/download?logfile=totalcms.log')->assertForbidden();
});
