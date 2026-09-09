<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;

/**
 * The schema sidebar groups embedded sub-schemas (the cards other schemas
 * reference) under "Internal". They stay listed and selectable, but the group
 * starts collapsed so the everyday schemas are not pushed down — unless the
 * schema being viewed lives in it, in which case it opens so the active link
 * is visible.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

it('lists the Internal group collapsed and the others open', function (): void {
	$body = (string)get('/admin/schemas')->getBody();

	expect($body)->toMatch('/<details id="category-Internal">/')
		->toMatch('/<details id="category-Built-in-Schemas" open>/')
		->toContain('href="schemas/sitemap-meta"');
});

it('opens the Internal group when the viewed schema is inside it', function (): void {
	$body = (string)get('/admin/schemas/sitemap-meta')->getBody();

	expect($body)->toMatch('/<details id="category-Internal" open>/');
});
