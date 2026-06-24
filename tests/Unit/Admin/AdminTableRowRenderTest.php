<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\ContainerFactory;

/**
 * Regression: indexing a card (or any composite/array-valued) column crashed the
 * admin table — admin/collection/table-row.twig fell through to
 * `{{ value|striptags }}`, and strip_tags() rejects an array. Table items come
 * from the index as raw arrays (not Stringable data objects), so a populated
 * card reached striptags() as an array and threw.
 *
 * TwigEngine wraps render exceptions in a `.cms-twig-error` block, so we assert
 * that marker is absent rather than expecting a thrown exception.
 */
function renderTableRow(array $object, array $columns): string
{
	static $twig = null;
	$twig ??= ContainerFactory::build()->get(TwigEngine::class);

	return $twig->render('admin/collection/table-row.twig', [
		'object'         => $object,
		'_collection'    => 'nested-uploads',
		'_columns'       => $columns,
		'_labelSingular' => 'Item',
		'_collectionUrl' => '',
		'_objectUrl'     => '',
	]);
}

test('card column renders a scalar summary without crashing', function (): void {
	$object = ['id' => 'x', 'mycard' => [
		'id'         => 'c1',
		'name'       => 'My Card',
		'styledtext' => '<p>a <b>note</b></p>',
		'image'      => ['name' => 'a.jpg', 'tags' => ['x']],
		'file'       => ['name' => 'a.pdf'],
	]];

	$html = renderTableRow($object, [['name' => 'mycard', 'type' => 'card']]);

	expect($html)->not->toContain('cms-twig-error');
	expect($html)->toContain('My Card');   // scalar field shown
	expect($html)->toContain('a note');    // styledtext, tags stripped
	expect($html)->not->toContain('c1');   // internal id skipped
	expect($html)->not->toContain('a.jpg'); // nested image/file skipped
});

test('deck column renders the item count', function (): void {
	$object = ['id' => 'x', 'mydeck' => ['one' => ['name' => '1'], 'two' => ['name' => '2']]];

	$html = renderTableRow($object, [['name' => 'mydeck', 'type' => 'deck']]);

	expect($html)->not->toContain('cms-twig-error');
	expect($html)->toContain('2'); // item count
});

test('an unhandled composite column falls back to a count instead of crashing', function (): void {
	$object = ['id' => 'x', 'weird' => ['a' => 1, 'b' => 2, 'c' => 3]];

	$html = renderTableRow($object, [['name' => 'weird', 'type' => 'mysteryarray']]);

	expect($html)->not->toContain('cms-twig-error');
});
