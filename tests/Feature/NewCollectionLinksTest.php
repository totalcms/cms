<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Service\TwigEngine;

use function TotalCMS\Slim\Pest\get;

/**
 * Every link to /collections/new follows the rule the page enforces: create
 * on `collectionsMeta`. The `schema-editor` group holds it without being an
 * admin, and `editor` can create objects but not collections.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->welcomePanel = fn (): string => $this->app->getContainer()->get(TwigEngine::class)->renderString(
		"{% import 'dashboard-widgets.twig' as dashboard %}{{ dashboard.newUserWelcome() }}",
	);
});

test('the collections sidebar offers New Collection to a non-admin who may create collections', function (): void {
	signInAs($this->app, 'schema-editor-user-test-com', 'auth');

	expect((string)get('/admin/collections')->getBody())->toContain('href="collections/new"');
});

test('the collections sidebar hides New Collection from a user who may not', function (): void {
	signInAs($this->app, 'editor-user-test-com', 'auth');

	expect((string)get('/admin/collections')->getBody())->not->toContain('href="collections/new"');
});

test('the welcome panel offers a first collection to a user who may create one', function (): void {
	signInAs($this->app, 'schema-editor-user-test-com', 'auth');

	expect(($this->welcomePanel)())->toContain('href="collections/new"');
});

test('the welcome panel leaves out the first collection card for a user who may not', function (): void {
	signInAs($this->app, 'editor-user-test-com', 'auth');

	expect(($this->welcomePanel)())->not->toContain('href="collections/new"')->toContain('href="docs"');
});

test('the new object shortcut is Control+N on a Mac and Alt+N elsewhere, leaving Cmd+N to the browser', function (): void {
	$html = (string)get('/admin/collections/auth')->getBody();

	expect($html)->toContain('const newObjectKey = navigator.platform.startsWith("Mac") ? event.ctrlKey : event.altKey && !event.ctrlKey;');
	expect($html)->toContain('if (newObjectKey && event.key.toLowerCase() === "n")');
	expect($html)->not->toContain('(event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "n"');
});
