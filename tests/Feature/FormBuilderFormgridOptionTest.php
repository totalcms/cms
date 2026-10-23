<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;

/**
 * The form builder's `formgrid` option lays out a schema form.
 *
 * A schema-driven form always took its grid from the schema, so
 * `cms.form.builder('users', {formgrid: …})` was silently ignored: the
 * option existed, validated, and did nothing. A front-end form showing a
 * subset of a schema's fields (a member's profile editor) needs its own
 * layout and section headers without changing the admin's.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'posts', 'name' => 'Posts', 'schema' => 'blog']);

	$this->factory = $c->get(TotalFormFactory::class);
});

/** The first grid-template-areas of the form's style tag (the one-column layout). */
function gridAreas(string $html): string
{
	return preg_match('/grid-template-areas:([^;]*);/', $html, $m) === 1 ? preg_replace('/\s+/', ' ', trim($m[1])) : '';
}

it('lays out a schema form with the formgrid option when one is given', function (): void {
	$form = $this->factory->builder('posts', ['formgrid' => "--- The story\ntitle\nsummary"]);
	$form->addField('title');
	$form->addField('summary');
	$html = $form->build();

	expect($html)->toContain('form-grid-section-header');
	expect($html)->toContain('The story');
	// The schema's grid (id, draft, featured, author…) is not the one used.
	expect(gridAreas($html))->not->toContain("'draft'");
	expect(gridAreas($html))->toContain("'title'");
});

it('keeps the schema formgrid when the option is not given', function (): void {
	$form = $this->factory->builder('posts');
	$form->addField('title');
	$html = $form->build();

	expect(gridAreas($html))->toContain("'draft'");
	expect($html)->not->toContain('The story');
});
