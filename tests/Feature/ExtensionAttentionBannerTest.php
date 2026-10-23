<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

use function TotalCMS\Slim\Pest\get;

/**
 * An extension the system turns off by itself (an update added risky code,
 * or it crashed repeatedly) does so on a request nobody is watching. Every
 * admin page now says so to admins until someone re-enables or disables it.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->states = $this->app->getContainer()->get(ExtensionStateRepository::class);
});

function attentionUpdateDisabled(ExtensionStateRepository $states): void
{
	$states->saveState('acme/shots', new ExtensionState(
		enabled: false,
		version: '1.2.0',
		updateDisabled: [
			'reason'      => 'A new version of this extension added risky code patterns.',
			'findings'    => 1,
			'updatedAt'   => '2026-10-21T02:04:00+00:00',
			'newFindings' => ['exec @ src/Runner.php'],
		],
	));
}

it('warns admins on every admin page about an extension disabled after an update', function (): void {
	attentionUpdateDisabled($this->states);
	signInAs($this->app, 'admin-user-test-com', 'auth');

	$html = (string)get('/admin/utils')->getBody();

	expect($html)->toContain('extension-attention')
		->and($html)->toContain('Extension acme/shots was disabled after an update added risky code: exec @ src/Runner.php.')
		->and($html)->toContain('href="extensions/acme/shots/settings"');
});

it('warns about a quarantined extension', function (): void {
	$this->states->saveState('acme/crashy', new ExtensionState(
		enabled: false,
		quarantine: ['reason' => 'crashes', 'failureCount' => 5, 'lastError' => 'Call to undefined function boom()', 'quarantinedAt' => '2026-10-21T02:04:00+00:00'],
	));
	signInAs($this->app, 'admin-user-test-com', 'auth');

	expect((string)get('/admin/utils')->getBody())
		->toContain('Extension acme/crashy was disabled after it failed repeatedly: Call to undefined function boom().');
});

it('does not show the warning to non-admins', function (): void {
	attentionUpdateDisabled($this->states);
	signInAs($this->app, 'editor-user-test-com', 'auth');

	expect((string)get('/admin')->getBody())->not->toContain('extension-attention');
});

it('stops warning once an operator disables the extension themselves', function (): void {
	attentionUpdateDisabled($this->states);
	$this->app->getContainer()->get(ExtensionManager::class)->disable('acme/shots');
	signInAs($this->app, 'admin-user-test-com', 'auth');

	expect($this->states->getState('acme/shots')?->isUpdateDisabled())->toBeFalse()
		->and((string)get('/admin/utils')->getBody())->not->toContain('extension-attention');
});

it('stops warning about a quarantine once an operator disables the extension', function (): void {
	$this->states->saveState('acme/crashy', new ExtensionState(
		enabled: false,
		quarantine: ['reason' => 'crashes', 'failureCount' => 5, 'lastError' => 'boom', 'quarantinedAt' => '2026-10-21T02:04:00+00:00'],
	));
	$this->app->getContainer()->get(ExtensionManager::class)->disable('acme/crashy');

	expect($this->states->getState('acme/crashy')?->isQuarantined())->toBeFalse();
});
