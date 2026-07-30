<?php

declare(strict_types=1);

namespace Tests\Unit\JavaScript;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Sync Manager selection serialization in
 * `resources/templates/admin/utils/sync.twig`.
 *
 * Regression: `multicheckbox` renders each option with a BARE `name` (no `[]`),
 * so the JS that collects checked items must select `input[name="X"]:checked`,
 * NOT `input[name="X[]"]:checked`. The bracketed selector matched nothing, so a
 * "specific" selection silently collapsed to `mode=none` and synced nothing —
 * only the "All" checkbox (read with a bare selector) worked. This hit schemas,
 * templates, and per-collection objects alike.
 */
final class SyncSelectionSelectorTest extends TestCase
{
	private function syncTemplate(): string
	{
		return (string)file_get_contents(__DIR__ . '/../../../resources/templates/admin/utils/sync.twig');
	}

	public function testCheckedItemSelectorsUseBareNamesNotBracketedNames(): void
	{
		$js = $this->syncTemplate();

		// No `input[name="…[]"]:checked` read-selector anywhere — that never
		// matches multicheckbox's bare option name.
		$this->assertStringNotContainsString('[]"]:checked', $js);

		// Every selection read goes through readSelection(), whose checked-item
		// query uses the bare field name; the per-collection sections pass
		// their bare names into it.
		$this->assertStringContainsString('input[name="${itemsFieldName}"]:checked', $js);
		$this->assertStringContainsString('readSelection(`collection-${cid}-all`, `collection-${cid}`)', $js);
	}
}
