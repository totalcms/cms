<?php

declare(strict_types=1);

/**
 * The route passed to `cms.form.totalform()` is relative to the form's
 * `data-api` attribute, and TotalFormFactory::totalform() already sets that to
 * `config->api . '/api'`. The JS concatenates the two verbatim
 * (TotalCMS::buildApiQuery), so a route that starts with `/api` posts to
 * `/api/api/...` and 404s on every install, root or subfolder.
 *
 * That is exactly how the OAuth client create form shipped: every submission
 * failed with a 404 that looked like a routing or permissions problem, and the
 * form is rare enough — most MCP clients self-register via RFC 7591 — that it
 * went unnoticed through several release candidates.
 *
 * `/admin/...` routes are the documented exception: TotalFormFactory dispatches
 * those against the unprefixed base path, because the admin surface lives
 * outside the `/api` group.
 */
test('no totalform route carries its own /api prefix', function (): void {
	$templateDir = dirname(__DIR__, 2) . '/resources/templates';

	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateDir));

	$offenders = [];
	foreach ($files as $file) {
		if (!$file instanceof SplFileInfo || $file->getExtension() !== 'twig') {
			continue;
		}

		$contents = (string)file_get_contents($file->getPathname());

		// cms.form.totalform("/api/oauth-clients", …  — literal routes only;
		// a variable route (access-groups passes `apiRoute`) can't be checked here.
		if (preg_match_all('/form\.(?:totalform|simple)\(\s*"(\/api(?:\/[^"]*)?)"/', $contents, $matches) === 0) {
			continue;
		}

		foreach ($matches[1] as $route) {
			$offenders[] = sprintf(
				'%s: "%s"',
				str_replace($templateDir . '/', '', $file->getPathname()),
				$route
			);
		}
	}

	expect($offenders)->toBe([], "Form route(s) starting with '/api': "
		. implode(', ', $offenders)
		. '. data-api already carries the /api prefix, so these post to /api/api/… '
		. 'and 404. Drop the prefix — pass the bare route.');
});
