<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormGridBuilder;

/**
 * FormGridBuilder translates schema `formgrid` text into CSS grid layouts and
 * the companion section-header/divider HTML. It is consumed by TotalForm and
 * by DeckItem's new formgrid-in-dialog feature, so regressions here silently
 * break form layouts across the admin.
 */
describe('FormGridBuilder', function (): void {
	test('toStyleTag generates mobile-first responsive CSS with container queries', function (): void {
		$builder = new FormGridBuilder("title date\ncontent content");

		$style = $builder->toStyleTag('form-123');

		expect($style)->toContain('<style>');
		expect($style)->toContain('#form-123-container');
		expect($style)->toContain('container-type: inline-size');
		expect($style)->toContain('#form-123');
		// Mobile: single column, each area on its own row
		expect($style)->toContain("'title'");
		expect($style)->toContain("'date'");
		expect($style)->toContain("'content'");
		// Desktop: multi-column inside a @container query
		expect($style)->toContain('@container (min-width: 500px)');
		expect($style)->toContain("'title date'");
		expect($style)->toContain("'content content'");
	});

	test('toStyleTag returns empty string for empty formgrid', function (): void {
		$builder = new FormGridBuilder('');

		expect($builder->toStyleTag('form-123'))->toBe('');
	});

	test('toStyleTag deduplicates repeated areas in the mobile layout', function (): void {
		// A field that spans multiple columns (e.g. `content content`) should
		// appear only once in the mobile single-column layout, not twice.
		$builder = new FormGridBuilder("title title\ncontent content\nfooter footer");

		$style = $builder->toStyleTag('form-xyz');

		// Count how many times `'title'` (exact, single-quoted) shows up in
		// the mobile section. Mobile flattens spanning cells to one row each.
		$mobileSegment = substr($style, 0, strpos($style, '@container') ?: strlen($style));
		expect(substr_count($mobileSegment, "'title'"))->toBe(1);
		expect(substr_count($mobileSegment, "'content'"))->toBe(1);
		expect(substr_count($mobileSegment, "'footer'"))->toBe(1);
	});

	test('buildGridSectionHtml emits hr for dividers and h3 for headers', function (): void {
		$builder = new FormGridBuilder("title date\n---\ncontent content\n---My Section---\nimage image");

		$html = $builder->buildGridSectionHtml();

		expect($html)->toContain('<hr');
		expect($html)->toContain('form-grid-section-divider');
		expect($html)->toContain('grid-area: section-divider-1');
		expect($html)->toContain('<h3');
		expect($html)->toContain('form-grid-section-header');
		expect($html)->toContain('grid-area: section-header-2');
		expect($html)->toContain('My Section');
	});

	test('buildGridSectionHtml escapes header titles', function (): void {
		$builder = new FormGridBuilder('---<script>bad</script>---');

		$html = $builder->buildGridSectionHtml();

		expect($html)->not->toContain('<script>bad</script>');
		expect($html)->toContain('&lt;script&gt;');
	});

	test('ensureFieldsIncluded appends missing fields as full-width rows', function (): void {
		$builder = new FormGridBuilder('title date');

		$builder->ensureFieldsIncluded(['title', 'date', 'content', 'summary']);

		$style = $builder->toStyleTag('form-test');

		// title/date stay in their original row; content and summary each get
		// a full-width row repeating their name across every column.
		expect($style)->toContain("'title date'");
		expect($style)->toContain("'content content'");
		expect($style)->toContain("'summary summary'");
	});

	test('ensureFieldsIncluded leaves already-present fields alone', function (): void {
		$builder = new FormGridBuilder("title content\ndate author");

		$builder->ensureFieldsIncluded(['title', 'date']);

		$style = $builder->toStyleTag('form-test');

		expect(substr_count($style, 'title'))->toBe(2); // desktop + mobile
		expect(substr_count($style, 'date'))->toBe(2);  // desktop + mobile
	});

	test('ensureFieldsIncluded ignores empty cell markers and invalid names', function (): void {
		$builder = new FormGridBuilder('title date');

		$builder->ensureFieldsIncluded(['.', '123invalid', 'valid_name']);

		$style = $builder->toStyleTag('form-test');

		expect($style)->toContain("'valid_name valid_name'");
		// The `.` marker and invalid-name row should not appear as cells
		// beyond their normal empty-cell usage.
		expect($style)->not->toContain("'123invalid 123invalid'");
	});

	test('invalid area names on a line cause that line to be skipped', function (): void {
		$builder = new FormGridBuilder("title date\n123invalid 123invalid\ncontent content");

		$style = $builder->toStyleTag('form-test');

		expect($style)->toContain("'title date'");
		expect($style)->toContain("'content content'");
		expect($style)->not->toContain('123invalid');
	});

	test('hasGrid reports whether any usable layout lines were provided', function (): void {
		expect((new FormGridBuilder(''))->hasGrid())->toBeFalse();
		expect((new FormGridBuilder("\n\n"))->hasGrid())->toBeFalse();
		expect((new FormGridBuilder('title date'))->hasGrid())->toBeTrue();
	});

	test('renderLayout wraps content in a plain .formgrid div when no layout is defined', function (): void {
		$builder = new FormGridBuilder('');

		$html = $builder->renderLayout('<p>fields</p>');

		// No style tag, no container-queries wrapper - just the grid div so CSS
		// (`.formgrid { display: grid; gap: 1rem; }`) provides vertical spacing.
		expect($html)->toBe('<div class="formgrid"><p>fields</p></div>');
	});

	test('renderLayout appends an extra class alongside .formgrid', function (): void {
		$builder = new FormGridBuilder('');

		$html = $builder->renderLayout('<p>fields</p>', 'deckitem-formgrid');

		expect($html)->toContain('class="formgrid deckitem-formgrid"');
		expect($html)->toContain('<p>fields</p>');
	});

	test('renderLayout with a layout emits style tag, container wrapper, grid div and section HTML', function (): void {
		$builder = new FormGridBuilder("title date\n---Meta---\nauthor author");

		$html = $builder->renderLayout('<p>fields</p>');

		// Style tag for grid-template-areas
		expect($html)->toContain('<style>');
		expect($html)->toContain('container-type: inline-size');
		// Outer container-queries wrapper uses `-container` id suffix of the grid id
		expect($html)->toMatch('/<div id="formgrid-[0-9a-f]+-container"><div id="formgrid-[0-9a-f]+" class="formgrid">/');
		// Section HTML (header) appears inside the grid, before the fields
		expect($html)->toContain('<h3');
		expect($html)->toContain('Meta');
		// Fields appear after the section HTML
		expect($html)->toContain('<p>fields</p>');
		expect(strpos($html, '<h3'))->toBeLessThan(strpos($html, '<p>fields</p>'));
	});

	test('renderLayout generates a unique id per call so multiple grids can coexist', function (): void {
		$builder = new FormGridBuilder('title date');

		$one = $builder->renderLayout('a');
		$two = $builder->renderLayout('b');

		preg_match('/id="(formgrid-[0-9a-f]+)"/', $one, $m1);
		preg_match('/id="(formgrid-[0-9a-f]+)"/', $two, $m2);

		expect($m1[1] ?? '')->not->toBe('');
		expect($m2[1] ?? '')->not->toBe('');
		expect($m1[1])->not->toBe($m2[1]);
	});

	test('a fieldset occupies a full-width row in the outer desktop areas', function (): void {
		$b   = new FormGridBuilder("id name\n[[\na b\nc d\n]]");
		$css = $b->toStyleTag('grid-x');
		// outer grid is 2 columns; the fieldset area spans both:
		expect($css)->toContain("'formgrid-fieldset-1 formgrid-fieldset-1'");
		// inner member rows are NOT in the outer template:
		expect($css)->not->toContain("'a b'");
	});

	test('toNestedStyleTag emits grid areas without a container-type declaration', function (): void {
		$inner = (new FormGridBuilder("a b\nc d"));
		$css   = $inner->toNestedStyleTag('fs-1');
		expect($css)->toContain('#fs-1')->toContain("'a b'")->not->toContain('container-type');
	});

	test('a fieldset-only formgrid emits a valid full-width desktop area (no repeat(0))', function (): void {
		$b   = new FormGridBuilder("[[ Legend\na b\nc d\n]]");
		$css = $b->toStyleTag('grid-z');
		// no outer rows, but the fieldset still gets a 1-column full-width area:
		expect($css)
			->toContain("'formgrid-fieldset-1'")
			->toContain('repeat(1, 1fr)')
			->not->toContain('repeat(0');
	});
});
