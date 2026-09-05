<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;

/**
 * Renders the Update Manager for real.
 *
 * The rest of the update work can only be exercised by performing an update,
 * so this page's markup — the keep-a-copy checkbox, the previous-version panel,
 * their translation keys — would otherwise ship unverified. A Twig error or a
 * missing `t()` key here is invisible to every other test in the suite.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

it('renders without error', function (): void {
	$response = get('/admin/utils/update');

	expect($response->getStatusCode())->toBe(200);
	expect((string)$response->getBody())->toContain('Update Manager');
});

it('resolves every translation key it uses', function (): void {
	$body = (string)get('/admin/utils/update')->getBody();

	// A missing key renders as the key itself.
	expect($body)->not->toContain('update.keep_backup');
	expect($body)->not->toContain('update.previous');
	expect($body)->not->toContain('update.previous_hint');
	expect($body)->not->toContain('update.previous_delete');
});

it('shows no previous-version panel when nothing is retained', function (): void {
	expect((string)get('/admin/utils/update')->getBody())->not->toContain('id="update-previous"');
});

it('shows the previous-version panel when a copy is retained', function (): void {
	$backup = cmsDataDir() . '.system/backups/3.4.9-20260101-120000';
	mkdir($backup, 0755, true);
	file_put_contents($backup . '/version.json', str_repeat('x', 2048));

	$body = (string)get('/admin/utils/update')->getBody();

	expect($body)->toContain('id="update-previous"');
	expect($body)->toContain('3.4.9-20260101-120000');
	expect($body)->toContain('delete-backup-btn');
});
