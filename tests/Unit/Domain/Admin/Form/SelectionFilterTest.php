<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\Form\SelectionFilter;

test('returns the ticked ids', fn () => expect(SelectionFilter::ticked(['x' => ['a', 'b']], 'x'))->toBe(['a', 'b']));
test('missing key is empty', fn () => expect(SelectionFilter::ticked([], 'x'))->toBe([]));
test('blanks are stripped', fn () => expect(SelectionFilter::ticked(['x' => ['a', '', 'b']], 'x'))->toBe(['a', 'b']));
