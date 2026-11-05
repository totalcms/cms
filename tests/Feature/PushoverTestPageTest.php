<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;

/** The Pushover extension's admin test page, which renders from the extension's own templates. */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	is_dir(cmsDataDir() . '.system') || mkdir(cmsDataDir() . '.system', 0755, true);
	file_put_contents(cmsDataDir() . '.system/extensions.json', json_encode(['totalcms/pushover' => ['enabled' => true]]));
	$this->setUpApp(bootstrap());
	signInAs($this->app, 'admin-user-test-com');
});

test('the test page renders', function (): void {
	$response = get('/admin/ext/totalcms/pushover/test');

	expect($response->getStatusCode())->toBe(200);
	expect((string)$response->getBody())->toContain('Send a test notification');
});
