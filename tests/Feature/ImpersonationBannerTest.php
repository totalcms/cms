<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Service\ImpersonationServiceInterface;
use TotalCMS\Domain\Object\Service\ObjectSaver;

use function TotalCMS\Slim\Pest\get;

/** The badge through the real app: the container wiring and the session user's record. */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(ObjectSaver::class)->saveObject('auth', [
		'id' => 'client-user', 'name' => 'Maria Alvarez', 'email' => 'maria@example.test', 'password' => 'client-password-1', 'groups' => ['editor'],
	]);
	signInAs($this->app, 'admin-user-test-com', 'auth');
	$this->app->getContainer()->get(ImpersonationServiceInterface::class)->start('auth', 'client-user');
});

test('the badge names the impersonated user', function (): void {
	$html = (string)get('/admin')->getBody();

	expect($html)->toContain('Impersonating <strong style="margin-left:4px;">Maria Alvarez</strong>');
	expect($html)->toContain('aria-label="Return to your account"');
});
