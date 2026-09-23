<?php

declare(strict_types=1);

use TotalCMS\Domain\Visualizer\Service\MermaidIdAllocator;

// The id rule the ERD and flowchart renderers used to each carry.
test('keys become safe identifiers and stay stable', function (): void {
	$ids = new MermaidIdAllocator();

	expect($ids->idFor('blog:post-1'))->toBe('blog_post_1')
		->and($ids->idFor('blog:post-1'))->toBe('blog_post_1')
		->and($ids->idFor('2024'))->toBe('n_2024')
		->and($ids->idFor(''))->toBe('n_');
});

test('two keys that sanitize alike get numbered apart', function (): void {
	$ids = new MermaidIdAllocator();

	expect($ids->idFor('a:b'))->toBe('a_b')
		->and($ids->idFor('a/b'))->toBe('a_b_2')
		->and($ids->idFor('a.b'))->toBe('a_b_3');
});
