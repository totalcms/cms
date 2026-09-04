<?php

declare(strict_types=1);

use TotalCMS\Domain\Template\Data\TemplatePath;

describe('TemplatePath::parse', function (): void {
	test('splits on the last slash', function (): void {
		expect(TemplatePath::parse('grids/card'))->toBe(['grids', 'card']);
	});

	test('treats a bare name as having no folder', function (): void {
		expect(TemplatePath::parse('card'))->toBe([null, 'card']);
	});
});

/**
 * The Designer channel is rooted at the `templates` builder category on BOTH
 * ends: the local save in TemplateDesignerSync and the remote lookup in
 * DesignerAccessMiddleware both go through here so they cannot drift.
 */
describe('TemplatePath::parseInTemplates', function (): void {
	test('roots a bare name at the templates category', function (): void {
		expect(TemplatePath::parseInTemplates('myblog'))->toBe(['templates', 'myblog']);
	});

	test('strips a redundant leading templates/ segment', function (): void {
		expect(TemplatePath::parseInTemplates('templates/myblog'))->toBe(['templates', 'myblog']);
	});

	test('nests a sub folder under the templates category', function (): void {
		expect(TemplatePath::parseInTemplates('grids/card'))->toBe(['templates/grids', 'card']);
	});

	test('strips the redundant prefix before nesting', function (): void {
		expect(TemplatePath::parseInTemplates('templates/grids/card'))->toBe(['templates/grids', 'card']);
	});

	test('tolerates a leading slash', function (): void {
		expect(TemplatePath::parseInTemplates('/myblog'))->toBe(['templates', 'myblog']);
		expect(TemplatePath::parseInTemplates('/templates/myblog'))->toBe(['templates', 'myblog']);
	});

	test('keeps a template legitimately named "templates"', function (): void {
		expect(TemplatePath::parseInTemplates('templates'))->toBe(['templates', 'templates']);
	});
});

/**
 * The loader roots are the builder read layers, so a user template resolves as
 * `templates/<id>.twig` relative to them.
 */
describe('TemplatePath::loaderPath', function (): void {
	test('roots a bare id in the templates category', function (): void {
		expect(TemplatePath::loaderPath('myblog'))->toBe('templates/myblog.twig');
	});

	test('accepts an id that already carries the extension', function (): void {
		expect(TemplatePath::loaderPath('myblog.twig'))->toBe('templates/myblog.twig');
	});

	test('nests a sub folder, as the docs examples use', function (): void {
		expect(TemplatePath::loaderPath('blog/card.twig'))->toBe('templates/blog/card.twig');
	});

	test('does not double the category', function (): void {
		expect(TemplatePath::loaderPath('templates/myblog'))->toBe('templates/myblog.twig');
		expect(TemplatePath::loaderPath('templates/blog/card.twig'))->toBe('templates/blog/card.twig');
	});
});
