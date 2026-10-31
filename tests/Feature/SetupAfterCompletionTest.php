<?php

declare(strict_types=1);

use TotalCMS\Domain\Setup\Service\SetupStateManager;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;

/**
 * Once setup is complete the wizard is closed, however the path is spelled.
 *
 * SetupCheckMiddleware compared the raw path with `/setup`, but the router
 * matches the URL-decoded one, so `/%73etup/account` passed the check and
 * reached the wizard: an anonymous visitor could create an admin on any
 * finished install and was signed in as it (security scan, 2026-10-30,
 * confirmed on the test site). The same held for every other step — moving
 * the data folder, replacing the license.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	$this->setUpApp(bootstrap());
	expect($this->app->getContainer()->get(SetupStateManager::class)->isSetupComplete())->toBeTrue();
});

function authUserCount(): int
{
	return count(glob(cmsDataDir() . 'auth/*.json') ?: []);
}

it('closes the wizard to an encoded path', function (string $path): void {
	$response = get($path);

	expect($response->getStatusCode())->toBe(302)
		->and((string)$response->getBody())->not->toContain('name="password"');
})->with(['/setup/account', '/%73etup/account', '/%73%65%74%75%70/data-path', '/setup/%61ccount']);

it('does not create an admin through an encoded setup path', function (): void {
	$before = authUserCount();

	$response = post('/%73etup/account', [
		'name'             => 'Intruder',
		'email'            => 'intruder@example.test',
		'password'         => 'not-a-real-password-1',
		'password-confirm' => 'not-a-real-password-1',
	]);

	expect($response->getStatusCode())->toBe(302)
		->and(authUserCount())->toBe($before)
		->and((string)json_encode(array_map('file_get_contents', glob(cmsDataDir() . 'auth/*.json') ?: [])))->not->toContain('intruder@example.test');
});
