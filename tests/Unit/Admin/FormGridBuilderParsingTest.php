<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\Form\Layout\FormGridBuilder;

describe('FormGridBuilder parsing', function (): void {
	test('--- is a divider, --- X and --- X --- are headers', function (): void {
		$b = new FormGridBuilder("a\n---\nb\n--- New Header\nc\n--- Old Header ---");
		// section titles, in order, via the public getSections-like accessor:
		expect($b->sectionTitles())->toBe(['New Header', 'Old Header']);
		expect($b->dividerCount())->toBe(1);
	});

	test('parses a [[ ]] block into a fieldset with legend, members and inner grid', function (): void {
		$b  = new FormGridBuilder("id id\n[[ My Legend\nfield1 field2\nfield3 field4\n]]");
		$fs = $b->getFieldsets();
		expect($fs)->toHaveCount(1);
		expect($fs[0]['legend'])->toBe('My Legend');
		expect($fs[0]['fields'])->toBe(['field1', 'field2', 'field3', 'field4']);
		expect($fs[0]['inner'])->toBeInstanceOf(FormGridBuilder::class);
		// the fieldset's members are NOT outer fields:
		expect($b->getFieldNames())->toBe(['id']);
	});

	test('[[ with no text means no legend; unclosed [[ is lenient', function (): void {
		$b  = new FormGridBuilder("[[\nfield1 field2");           // never closed
		$fs = $b->getFieldsets();
		expect($fs[0]['legend'])->toBeNull();
		expect($fs[0]['fields'])->toBe(['field1', 'field2']);
	});
});

describe('FormGridBuilder accordion parsing', function (): void {
	test('a single panel closed by << is one group of one', function (): void {
		$b = new FormGridBuilder(">> Advanced\nslug template\n<<");
		$c = $b->getContainers();

		expect($c)->toHaveCount(1);
		expect($c[0]['kind'])->toBe('accordion');
		expect($c[0]['id'])->toBe('formgrid-accordion-1');
		expect($c[0]['fields'])->toBe(['slug', 'template']);
		expect($c[0]['block']['panels'])->toHaveCount(1);
		expect($c[0]['block']['panels'][0]['title'])->toBe('Advanced');
	});

	test('consecutive >> panels before a << form one linked group', function (): void {
		$b = new FormGridBuilder(">> Content\nbody body\n>> SEO\nseoTitle seoDesc\n>> Advanced\nslug template\n<<");
		$c = $b->getContainers();

		expect($c)->toHaveCount(1);
		expect($c[0]['block']['panels'])->toHaveCount(3);
		expect(array_column($c[0]['block']['panels'], 'title'))->toBe(['Content', 'SEO', 'Advanced']);
		expect($c[0]['fields'])->toBe(['body', 'seoTitle', 'seoDesc', 'slug', 'template']);
	});

	test('two << terminated runs are two independent groups', function (): void {
		$b = new FormGridBuilder(">> Section 1\ntitle title\n<<\n>> Section 2\nid id\n<<");
		$c = $b->getContainers();

		expect($c)->toHaveCount(2);
		expect($c[0]['id'])->toBe('formgrid-accordion-1');
		expect($c[1]['id'])->toBe('formgrid-accordion-2');
		expect($c[0]['block']['panels'])->toHaveCount(1);
		expect($c[1]['block']['panels'])->toHaveCount(1);
	});

	test('an unterminated group swallows to end of formgrid', function (): void {
		$b = new FormGridBuilder("id id\n>> Only\na b");
		$c = $b->getContainers();

		expect($c)->toHaveCount(1);
		expect($c[0]['fields'])->toBe(['a', 'b']);
		expect($b->getFieldNames())->toBe(['id']);   // 'id' stays an outer row
	});

	test('a bare >> with no title falls back to Section N', function (): void {
		$b      = new FormGridBuilder(">>\na a\n>>\nb b\n<<");
		$panels = $b->getContainers()[0]['block']['panels'];

		expect($panels[0]['title'])->toBe('Section 1');
		expect($panels[1]['title'])->toBe('Section 2');
	});

	test('a stray << with no group open is ignored', function (): void {
		$b = new FormGridBuilder("id id\n<<\ntitle title");

		expect($b->getContainers())->toBe([]);
		expect($b->getFieldNames())->toBe(['id', 'title']);
	});

	test('a fieldset nests inside a panel', function (): void {
		$b     = new FormGridBuilder(">> Panel\nx x\n[[ Inner\ny z\n]]\n<<");
		$inner = $b->getContainers()[0]['block']['panels'][0]['inner'];

		expect($inner->getFieldNames())->toBe(['x']);
		expect($inner->getFieldsets())->toHaveCount(1);
		expect($inner->getFieldsets()[0]['legend'])->toBe('Inner');
		expect($inner->getFieldsets()[0]['fields'])->toBe(['y', 'z']);
	});

	test('a panel group nests inside a fieldset', function (): void {
		$b     = new FormGridBuilder("[[ Outer\n>> Panel\nx x\n<<\n]]");
		$inner = $b->getFieldsets()[0]['inner'];

		expect($inner->getContainers())->toHaveCount(1);
		expect($inner->getContainers()[0]['kind'])->toBe('accordion');
	});

	test('dividers and headers work inside a panel', function (): void {
		$b     = new FormGridBuilder(">> Panel\na a\n--- Sub Header\nb b\n<<");
		$inner = $b->getContainers()[0]['block']['panels'][0]['inner'];

		expect($inner->sectionTitles())->toBe(['Sub Header']);
	});

	test('getFieldsets still reports only fieldsets when both kinds are present', function (): void {
		$b = new FormGridBuilder("[[ F\na b\n]]\n>> P\nc d\n<<");

		expect($b->getContainers())->toHaveCount(2);
		expect($b->getFieldsets())->toHaveCount(1);
		expect($b->getFieldsets()[0]['legend'])->toBe('F');
		expect($b->getFieldsets()[0]['fields'])->toBe(['a', 'b']);
	});
});
