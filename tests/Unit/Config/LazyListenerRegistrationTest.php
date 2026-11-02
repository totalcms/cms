<?php

declare(strict_types=1);

// config/container.php wires internal event listeners as
// `$lazy(SomeListener::class, 'method')`: the method is looked up by name only
// when the event fires, and the dispatcher logs a failing listener instead of
// throwing. So a renamed or removed method is invisible until production logs
// an error on every event — CollectionMetadataListener::onObjectCreated was
// removed in 3.6.5 but stayed registered, and failed on every object create.
// This checks every registration against the class it names.

test('every lazy listener registration names a method that exists', function (): void {
	$source = (string)file_get_contents(dirname(__DIR__, 3) . '/config/container.php');

	preg_match_all('/^use\s+([\w\\\\]+?)(?:\s+as\s+(\w+))?;/m', $source, $uses, PREG_SET_ORDER);
	$imports = [];
	foreach ($uses as $use) {
		$alias           = $use[2] ?? '';
		$short           = $alias !== '' ? $alias : substr((string)strrchr('\\' . $use[1], '\\'), 1);
		$imports[$short] = $use[1];
	}

	preg_match_all('/\$lazy\(\s*(\w+)::class\s*,\s*\'(\w+)\'\s*\)/', $source, $registrations, PREG_SET_ORDER);
	expect($registrations)->not->toBeEmpty();

	$missing = [];
	foreach ($registrations as [, $short, $method]) {
		$class = $imports[$short] ?? $short;
		if (!class_exists($class) || !method_exists($class, $method)) {
			$missing[] = "{$short}::{$method}";
		}
	}

	expect($missing)->toBe([]);
});
