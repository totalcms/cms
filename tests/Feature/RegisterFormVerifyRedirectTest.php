<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;

// `cms.form.builder('members', {register: true, verifyRedirect: '/welcome'})`
// posts the target as a `verifyRedirect` field. It has to be a `.form-field`:
// the form script only submits values of fields it discovered.

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();

	// The blog fixture is a bare object folder; the form needs its collection record.
	bootstrap()->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'blog', 'name' => 'Blog', 'schema' => 'blog']);
});

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$this->factory = $this->app->getContainer()->get(TotalFormFactory::class);
});

it('emits a hidden verifyRedirect field on a registration form', function (): void {
	$html = $this->factory->builder('blog', ['register' => true, 'verifyRedirect' => '/welcome'])->autoBuild();

	expect($html)->toMatch('/<div class="[^"]*form-field[^"]*"[^>]*data-type="hidden"[^>]*>.*?name="verifyRedirect"[^>]*value="\/welcome"/s');
});

it('emits no verifyRedirect field without the option', function (): void {
	expect($this->factory->builder('blog', ['register' => true])->autoBuild())->not->toContain('verifyRedirect');
});

it('emits nothing when the collection does not exist', function (): void {
	expect($this->factory->builder('no-such-collection', ['register' => true, 'verifyRedirect' => '/welcome'])->autoBuild())->not->toContain('verifyRedirect');
});

it('ignores verifyRedirect on a form that is not in register mode', function (): void {
	expect($this->factory->builder('blog', ['verifyRedirect' => '/welcome'])->autoBuild())->not->toContain('verifyRedirect');
});
