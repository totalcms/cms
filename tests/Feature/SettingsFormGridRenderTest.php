<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;

/**
 * A settings section renders its schema's `formgrid` — headers and all.
 *
 * TotalFormFactory::settings() renders each field itself and hands the finished
 * markup to TotalForm::build(). It never builds a SchemaData, and the grid was
 * gated entirely on having one — so a `formgrid` in a settings schema was
 * silently inert: valid, validated, and doing nothing. `general.json` carried a
 * dead one for exactly that reason.
 *
 * Driven through the real factory rather than a hand-built TotalForm: the
 * constructor takes fifteen collaborators, and stubbing them would test the
 * stubs rather than the seam.
 */
beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

it('renders the section headers defined by a settings formgrid', function (): void {
	$html = $this->app->getContainer()->get(TotalFormFactory::class)->settings('mcp');

	// Headers come from fieldContent() for schema-driven forms, which a
	// pre-rendered settings form never reaches.
	expect($html)->toContain('form-grid-section-header');

	foreach (['Public Access', 'Resource Subscriptions', 'SSE Probe Responses', 'Worker Budget'] as $section) {
		expect($html)->toContain($section);
	}
});

it('applies the grid class and template so the areas resolve', function (): void {
	$html = $this->app->getContainer()->get(TotalFormFactory::class)->settings('mcp');

	// Without both, headers would render but nothing would be laid out — fields
	// carry `--grid-area`, and only the generated style tag gives those names a
	// template to resolve against.
	expect($html)->toContain('formgrid');
	expect($html)->toContain('grid-template-areas');
});

it('leaves a section with no formgrid exactly as it was', function (): void {
	// Most settings schemas define no grid and must keep rendering one field per
	// row. This is the regression that would hit every other section if the new
	// gate were too eager.
	$html = $this->app->getContainer()->get(TotalFormFactory::class)->settings('cache');

	expect($html)->not->toContain('grid-template-areas');
	expect($html)->not->toContain('form-grid-section-header');
	// Still a real form with real fields.
	expect($html)->toContain('<form');
	expect($html)->toContain('form-field');
});
