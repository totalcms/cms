<?php

declare(strict_types=1);

use TotalCMS\Domain\Object\Service\NestedPath;

/**
 * The cursor walk ObjectUpdater and ObjectPatcher used to each spell out:
 * `obj[parent]` for a card child, `obj[parent][itemId][child]` for a deck
 * child, creating slots on the way and touching nothing else.
 */
test('a card child is the slot under the parent', function (): void {
	$data = ['mycard' => ['title' => 'keep', 'image' => 'old']];

	$slot = &NestedPath::slot($data, 'mycard', 'image');
	$slot = ['name' => 'new.jpg'];

	expect($data)->toBe(['mycard' => ['title' => 'keep', 'image' => ['name' => 'new.jpg']]]);
});

test('a deck child walks item then child, preserving siblings', function (): void {
	$data = ['mydeck' => ['one' => ['title' => 'A', 'file' => 'x'], 'two' => ['title' => 'B']]];

	$slot = &NestedPath::slot($data, 'mydeck', 'one/file');
	$slot = ['name' => 'a.pdf'];

	expect($data['mydeck']['one'])->toBe(['title' => 'A', 'file' => ['name' => 'a.pdf']])
		->and($data['mydeck']['two'])->toBe(['title' => 'B']);
});

test('missing parents and items are created on the way', function (): void {
	$data = ['title' => 'root'];

	$slot = &NestedPath::slot($data, 'mydeck', 'new-item/image');
	$slot = 'set';

	expect($data)->toBe(['title' => 'root', 'mydeck' => ['new-item' => ['image' => 'set']]]);
});

test('a non-array parent is replaced by a container', function (): void {
	$data = ['mycard' => 'scalar'];

	$slot = &NestedPath::slot($data, 'mycard', 'x');
	$slot = 1;

	expect($data)->toBe(['mycard' => ['x' => 1]]);
});

test('an existing leaf is handed back so a patch can merge into it', function (): void {
	$data = ['mycard' => ['image' => ['name' => 'a.jpg', 'alt' => 'A']]];

	$slot = &NestedPath::slot($data, 'mycard', 'image');
	$slot = array_merge($slot, ['alt' => 'B']);

	expect($data['mycard']['image'])->toBe(['name' => 'a.jpg', 'alt' => 'B']);
});
