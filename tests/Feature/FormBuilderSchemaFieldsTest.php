<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Service\CollectionSaver;

/**
 * `{{ cms.form.builder('contact', {addOnly: true}) }}` is the one-line form
 * the docs show. Printed without a single addField() it used to render a form
 * tag with no fields and no button. It now builds from the schema, and a form
 * that names its own fields or passes its own markup is left exactly as it was.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'posts', 'name' => 'Posts', 'schema' => 'blog']);

	$this->factory = $c->get(TotalFormFactory::class);
	$this->actors  = $c->get(ActorContext::class);
});

it('builds the fields and a save button when printed on its own', function (): void {
	$html = (string)$this->factory->builder('posts', ['addOnly' => true]);

	expect($html)->toContain('name="title"')
		->and($html)->toContain('name="summary"')
		->and($html)->toContain('type="submit"');
});

it('does the same through build()', function (): void {
	$html = $this->factory->builder('posts', ['addOnly' => true])->build();

	expect($html)->toContain('name="title"')
		->and($html)->toContain('type="submit"');
});

it('uses the save label it is given', function (): void {
	$html = (string)$this->factory->builder('posts', ['addOnly' => true, 'save' => 'Send it']);

	expect($html)->toContain('Send it');
});

it('leaves the button off when save is false', function (): void {
	$html = (string)$this->factory->builder('posts', ['addOnly' => true, 'save' => false]);

	expect($html)->toContain('name="title"')
		->and($html)->not->toContain('type="submit"');
});

it('leaves the button off an autosave form', function (): void {
	$html = (string)$this->factory->builder('posts', ['addOnly' => true, 'autosave' => true]);

	expect($html)->toContain('name="title"')
		->and($html)->not->toContain('type="submit"');
});

it('keeps to the fields a template adds, with no button unless asked', function (): void {
	$form = $this->factory->builder('posts', ['addOnly' => true]);
	$form->addField('title');
	$html = $form->build();

	expect($html)->toContain('name="title"')
		->and($html)->not->toContain('name="summary"')
		->and($html)->not->toContain('type="submit"');
});

it('keeps to the markup a template passes in', function (): void {
	$form = $this->factory->builder('posts', ['addOnly' => true]);
	$html = $form->build($form->field('title'));

	expect($html)->toContain('name="title"')
		->and($html)->not->toContain('name="summary"');
});

it('renders no button for a collection that does not exist', function (): void {
	$html = (string)$this->factory->builder('nothing-here', ['addOnly' => true]);

	expect($html)->toContain('nothing-here not found')
		->and($html)->not->toContain('type="submit"');
});

it('shows a visitor registering only the fields they may set', function (): void {
	$this->actors->set(Actor::anonymous());

	$html = (string)$this->factory->builder('auth', ['register' => true]);

	expect($html)->toContain('name="email"')
		->and($html)->toContain('name="password"')
		->and($html)->toContain('type="submit"')
		->and($html)->toContain('/admin/register/auth');

	foreach (['active', 'groups', 'expiration', 'maxLoginCount', 'loginCount', 'lastlogin', 'created'] as $privileged) {
		expect($html)->not->toContain('name="' . $privileged . '"');
	}
});
