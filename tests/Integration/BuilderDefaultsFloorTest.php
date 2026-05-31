<?php

declare(strict_types=1);

/**
 * Phase 4 of the git-first template workflow: built-in builder defaults ship
 * as the lowest read layer (`resources/builder/defaults/`). A page template can
 * extend `layouts/default.twig` and render even when neither the project nor
 * tcms-data provides that layout — it resolves from the floor.
 */

use TotalCMS\Domain\Twig\Service\TwigEngine;

it('renders a page using the built-in default layout from the floor', function (): void {
	$builderDir = cmsDataDir() . 'builder';
	$pagesDir   = $builderDir . '/pages';
	if (!is_dir($pagesDir)) {
		mkdir($pagesDir, 0o777, true);
	}

	// A user page template that extends the default layout...
	$template = $pagesDir . '/floortest.twig';
	file_put_contents($template, "{% extends 'layouts/default.twig' %}{% block content %}FLOOR-OK{% endblock %}");

	// ...with NO layouts/default.twig in tcms-data: it must come from the floor.
	expect(is_file($builderDir . '/layouts/default.twig'))->toBeFalse();

	try {
		// Fresh container AFTER the builder dir exists, so the loader includes it.
		$twig = bootstrap()->getContainer()->get(TwigEngine::class);
		$html = $twig->render('pages/floortest.twig', []);
	} finally {
		@unlink($template);
	}

	expect($html)->toContain('<!DOCTYPE html>'); // from the built-in floor layout
	expect($html)->toContain('FLOOR-OK');        // the page's own content block
});
