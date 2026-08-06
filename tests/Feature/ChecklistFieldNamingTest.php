<?php

declare(strict_types=1);

/**
 * Admin forms post JSON, not an encoded form body, so PHP's `name[]` convention
 * for collecting repeated inputs into an array does nothing here — TotalForm
 * keys the payload by the input's name verbatim. A field named `scopes[]`
 * therefore arrives as the literal key "scopes[]" and is silently ignored by an
 * action reading `scopes`, which is exactly how admin-created OAuth clients
 * ended up with no scopes.
 *
 * The convention every other multi-value field already follows is a plain name:
 * the field itself returns an array (see ChecklistField::getValue()), so the
 * brackets carry no information. This keeps it that way.
 *
 * Named subscripts (`fieldMap[title]`) are a different thing and stay allowed —
 * SimpleForm serializes raw DOM names and has no field object to group them, so
 * the name is the only place the grouping can live. See assignBracketKey() in
 * javascript/totalform/simpleform.js.
 */
test('no form field name uses the bare list suffix', function (): void {
	$templateDir = dirname(__DIR__, 2) . '/resources/templates';

	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateDir));

	$offenders = [];
	foreach ($files as $file) {
		if (!$file instanceof SplFileInfo || $file->getExtension() !== 'twig') {
			continue;
		}

		$contents = (string)file_get_contents($file->getPathname());

		// cms.form.field("checklist", "scopes[]", { … )
		if (preg_match_all('/form\.field\(\s*"[^"]*"\s*,\s*"([^"]*\[\])"/', $contents, $matches) === 0) {
			continue;
		}

		foreach ($matches[1] as $name) {
			$offenders[] = sprintf(
				'%s: "%s"',
				str_replace($templateDir . '/', '', $file->getPathname()),
				$name
			);
		}
	}

	expect($offenders)->toBe([], "Form field name(s) ending in '[]': "
		. implode(', ', $offenders)
		. '. Admin forms post JSON, so the brackets are never expanded and the value '
		. 'is dropped. Use a plain name — the field returns its own array.');
});
