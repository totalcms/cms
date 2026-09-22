<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;
use Twig\Node\EmptyNode;
use Twig\TwigFilter;

/**
 * The Twig side: registration, the option hash, and the empty/null shapes a
 * template hands a filter.
 */
test('typography is registered as an HTML-safe filter', function (): void {
	$filters = array_filter(TotalCMSTwigFilters::getFilters(), static fn (TwigFilter $f): bool => $f->getName() === 'typography');

	expect($filters)->toHaveCount(1)
		->and(array_values($filters)[0]->getSafe(new EmptyNode()))->toBe(['html']);
});

test('the filter applies the defaults and accepts the option hash', function (): void {
	expect(TotalCMSTwigFilters::typography('He said "it\'s" -- fine...'))->toBe('He said&nbsp;“it’s”—fine…')
		->and(TotalCMSTwigFilters::typography('He said "it\'s" -- fine...', ['widont' => false]))->toBe('He said “it’s”—fine…')
		->and(TotalCMSTwigFilters::typography('one - two 1/2', ['dashes' => 'en', 'fractions' => true, 'widont' => false]))->toBe('one – two ½')
		->and(TotalCMSTwigFilters::typography('"x"', ['quotes' => 'de']))->toBe('„x“');
});

test('empty and null input return an empty string', function (): void {
	expect(TotalCMSTwigFilters::typography(''))->toBe('')
		->and(TotalCMSTwigFilters::typography(null))->toBe('');
});

test('a value that is not a string is cast', function (): void {
	expect(TotalCMSTwigFilters::typography(42))->toBe('42');
});

test('an unknown option is a template error, not a silent default', function (): void {
	expect(fn () => TotalCMSTwigFilters::typography('x', ['quote' => 'de']))->toThrow(InvalidArgumentException::class);
});
