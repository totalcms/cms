<?php

use TotalCMS\Domain\Twig\Service\GridRenderer;

beforeEach(function () {
	$this->gridRenderer = new GridRenderer();
});

test('meta method formats data with HTML wrapper', function () {
	$result = $this->gridRenderer->meta('Test Author');
	expect($result)->toContain('Test Author');
	expect($result)->toContain('<'); // Should contain HTML
});

test('meta method handles empty data', function () {
	$result = $this->gridRenderer->meta('');
	expect($result)->toBe('');
});

test('tags method formats array of tags', function () {
	$tags   = ['PHP', 'Twig', 'CMS'];
	$result = $this->gridRenderer->tags($tags);

	expect($result)->toContain('PHP');
	expect($result)->toContain('Twig');
	expect($result)->toContain('CMS');
	expect($result)->toContain('<'); // Should contain HTML
});

test('tags method formats comma-separated string', function () {
	$tags   = 'PHP, Twig, CMS';
	$result = $this->gridRenderer->tags($tags);

	expect($result)->toContain('PHP');
	expect($result)->toContain('Twig');
	expect($result)->toContain('CMS');
});

test('tags method handles empty tags', function () {
	expect($this->gridRenderer->tags(null))->toBe('');
	expect($this->gridRenderer->tags(''))->toBe('');
	expect($this->gridRenderer->tags([]))->toBe('');
});

test('tags method with link base', function () {
	$tags   = ['PHP', 'Twig'];
	$result = $this->gridRenderer->tags($tags, '/tag/');

	expect($result)->toContain('PHP');
	expect($result)->toContain('Twig');
	expect($result)->toContain('/tag/'); // Should contain link base
});

test('date method formats date with HTML wrapper', function () {
	$result = $this->gridRenderer->date('2024-06-15');
	expect($result)->toContain('2024-06-15');
	expect($result)->toContain('cms-date'); // Should have CSS class
});

test('date method handles empty date', function () {
	$result = $this->gridRenderer->date('');
	expect($result)->toBe('');
});

test('date method with custom format', function () {
	$result = $this->gridRenderer->date('2024-06-15', 'short');
	expect($result)->toContain('<'); // Should contain HTML
	expect($result)->toContain('cms-date');
});

test('excerpt method truncates text', function () {
	$longText = str_repeat('Lorem ipsum dolor sit amet, ', 10);
	$result   = $this->gridRenderer->excerpt($longText, 50);

	expect(strlen(strip_tags($result)))->toBeLessThanOrEqual(55); // Allow for suffix
	expect($result)->toContain('cms-excerpt'); // Should have CSS class
	expect($result)->toContain('…'); // Should have ellipsis
});

test('excerpt method handles short text', function () {
	$shortText = 'Short text';
	$result    = $this->gridRenderer->excerpt($shortText, 50);

	expect($result)->toContain('Short text');
	expect($result)->not->toContain('…'); // Should not have ellipsis
});

test('excerpt method handles empty text', function () {
	expect($this->gridRenderer->excerpt(''))->toBe('');
	expect($this->gridRenderer->excerpt(null))->toBe('');
});

test('excerpt method with custom suffix', function () {
	$longText = str_repeat('Lorem ipsum ', 20);
	$result   = $this->gridRenderer->excerpt($longText, 50, '...');

	expect($result)->toContain('...');
	expect($result)->not->toContain('…');
});

test('price method returns formatted price with HTML wrapper', function () {
	$result = $this->gridRenderer->price(19.99);

	expect($result)->toContain('$19.99');
	expect($result)->toContain('cms-price'); // Should have CSS class
	expect($result)->toContain('<span'); // Should be wrapped in span
});

test('price method handles empty price', function () {
	expect($this->gridRenderer->price(''))->toBe('');
	expect($this->gridRenderer->price(null))->toBe('');
});

test('price method with custom currency and format', function () {
	$result = $this->gridRenderer->price(19.99, '€', 'append');

	expect($result)->toContain('19.99€');
	expect($result)->toContain('cms-price');
});

test('all methods handle edge cases gracefully', function () {
	// Test with various edge case inputs
	$edgeCases = [null, '', 0, '0', false];

	foreach ($edgeCases as $case) {
		// These should not throw exceptions
		if ($case !== null) {
			$this->gridRenderer->meta((string)$case);
			$this->gridRenderer->date((string)$case);
		}
		$this->gridRenderer->tags($case);
		$this->gridRenderer->excerpt($case);
		$this->gridRenderer->price($case);
	}

	expect(true)->toBeTrue(); // If we get here, no exceptions were thrown
});
