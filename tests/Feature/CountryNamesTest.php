<?php

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\ArrayLoader;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigExtension;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * `propertyOptions: "countries"` fills a select with every country, and the
 * `country_name` filter turns the stored code back into a name, with or
 * without Twig's IntlExtension loaded.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'notes', 'name' => 'Notes', 'schema' => 'text']);
});

it('fills a select from the countries source, code as value and name as label', function (): void {
	$form = $this->app->getContainer()->get(TotalFormFactory::class)->builder('notes', ['addOnly' => true]);

	$html = $form->field('text', ['field' => 'select', 'settings' => ['propertyOptions' => 'countries']]);

	expect($html)->toContain('<option value="US">United States</option>');
	expect($html)->toContain('<option value="JP">Japan</option>');
	expect(substr_count($html, '<option value="'))->toBeGreaterThan(240);
});

it('renders country_name through the site Twig engine', function (): void {
	$engine = $this->app->getContainer()->get(TwigEngine::class);

	expect($engine->renderString("{{ 'US'|country_name }}|{{ 'US'|country_name('de') }}|{{ ''|country_name }}", []))->toBe('United States|Vereinigte Staaten|');
});

it('provides country_name without the IntlExtension and escapes an unknown value', function (): void {
	$twig = new Environment(new ArrayLoader(['t' => "{{ 'DE'|country_name('en') }}|{{ code|country_name }}"]));
	$twig->addExtension($this->app->getContainer()->get(TotalCMSTwigExtension::class));

	expect($twig->render('t', ['code' => '<b>x</b>']))->toBe('Germany|&lt;b&gt;x&lt;/b&gt;');
});
