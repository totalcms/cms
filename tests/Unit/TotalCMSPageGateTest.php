<?php

declare(strict_types=1);

use TotalCMS\TotalCMS;

/**
 * On a Twig error, processBufferMacros() prints the page's source with the error
 * on top. On a page gated by protect_page() that source is what the gate hides,
 * and it can hold the passcode, so the gate must not fail open.
 *
 * Runs as a Stacks preview: outside one, the CLI constructor stops before the
 * buffer and the Twig engine exist.
 */
function renderBuffered(string $page): string
{
	$environment        = $_SERVER['APP_ENV'] ?? null;
	$_SERVER['APP_ENV'] = 'preview';

	try {
		$totalcms = new TotalCMS(false);
		$totalcms->startBuffer();
		echo $page;

		return $totalcms->processBufferMacros();
	} finally {
		$_SERVER['APP_ENV'] = $environment;
	}
}

test('a Twig error on a gated page shows the error without the page source', function (string $gate): void {
	$output = renderBuffered("<body>{$gate}<p>Client secrets</p>{{ cms.notAFunction(</body>");

	expect($output)->toContain('cms-twig-error')
		->not->toContain('Client secrets')
		->not->toContain('8675');
})->with([
	'with a passcode' => '{{ protect_page({passcode: "8675"}) }}',
	'trimmed'         => '{{- protect_page() -}}',
]);

test('a Twig error on any other page still shows the page with the error', function (): void {
	$output = renderBuffered('<body><p>Hello</p>{{ cms.notAFunction(</body>');

	expect($output)->toContain('cms-twig-error')->toContain('Hello');
});
