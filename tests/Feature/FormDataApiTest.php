<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Support\Config;

/**
 * A form's data-api is always the REST base (`{config api}/api`), including
 * forms that post to /admin/... (register forms, admin-routed totalforms).
 * The JS resolves /admin routes against the site base itself
 * (TotalCMS::buildApiQuery), so the form's other calls — the id "taken?"
 * check, the mailer action — still reach /api. When register forms carried
 * the site base instead, that base was '' on a root install, array_filter
 * dropped the attribute, and the handle check asked /collections/... (no
 * /api): a 404 every time, so every handle looked free.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'notes', 'name' => 'Notes', 'schema' => 'text']);

	$this->factory = $c->get(TotalFormFactory::class);
	$this->restApi = $c->get(Config::class)->api . '/api';
});

function dataApiFormTag(string $html): string
{
	preg_match('/<form\b[^>]*>/', $html, $m);

	return $m[0] ?? '';
}

test('a register form keeps the REST base as data-api and posts to /admin/register', function (): void {
	$tag = dataApiFormTag($this->factory->builder('notes', ['register' => true])->autoBuild());

	expect($tag)->toContain('data-api="' . $this->restApi . '"')
		->and($tag)->toContain('data-route="/admin/register/notes"');
});

test('an admin-routed totalform keeps the REST base as data-api', function (): void {
	$tag = dataApiFormTag($this->factory->totalform('/admin/settings/smtp'));

	expect($tag)->toContain('data-api="' . $this->restApi . '"')
		->and($tag)->toContain('data-route="/admin/settings/smtp"');
});
