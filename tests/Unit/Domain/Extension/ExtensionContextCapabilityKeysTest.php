<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\ExtensionContext;

// getCapabilities() used to hand-mirror 22 registration fields that had to
// match capabilityLabels() by eye. Both now read one table; this pins it.
test('every capability key has a label and every label a capability', function (): void {
	$keys   = ExtensionContext::capabilityKeys();
	$labels = array_keys(ExtensionContext::capabilityLabels());
	sort($keys);
	sort($labels);

	expect($keys)->toBe($labels);
});
