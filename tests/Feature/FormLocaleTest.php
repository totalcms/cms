<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Translation\TranslationService;

/**
 * A form's own text is translated as each field renders, so it follows the
 * language a page set with cms.locale.set() before building the form. The
 * builder used to make its forms without a translator, so every field's text
 * stayed English: a Spanish page's date field said "Clear date".
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'blog', 'name' => 'Blog', 'schema' => 'blog']);
	$this->factory    = $c->get(TotalFormFactory::class);
	$this->translator = $c->get(TranslationService::class);
	$this->siteLocale = $this->translator->getLocale();
});

afterEach(function (): void {
	$this->translator->setLocale($this->siteLocale);
});

it('renders a builder form field in the language set before it was built', function (): void {
	$this->translator->setLocale('es_ES');

	$form = $this->factory->builder('blog');
	$form->addField('date');
	$html = $form->build();

	expect($html)->toContain('aria-label="Borrar fecha"')
		->and($html)->not->toContain('Clear date');
});

it('keeps English for a site that never switches language', function (): void {
	$form = $this->factory->builder('blog');
	$form->addField('date');

	expect($form->build())->toContain('aria-label="Clear date"');
});
