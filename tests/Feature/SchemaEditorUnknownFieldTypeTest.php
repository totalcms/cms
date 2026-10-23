<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/**
 * A schema can name a field type this install does not list: an extension's,
 * while the extension is disabled. The schema editor's field-type <select>
 * used to have no option for it, so the browser picked the first option
 * (`code`) and the next save wrote that over the real type.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

it('keeps an unavailable field type selected in the schema editor', function (): void {
	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema(['id' => 'shots', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'image' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'screenshot'],
	]]);

	$html = $c->get(TotalFormFactory::class)->schema(['id' => 'shots']);

	expect($html)->toContain('<optgroup label="Unavailable (extension disabled or removed)">')
		->and($html)->toMatch('/<option value="screenshot"[^>]*selected/');
});
