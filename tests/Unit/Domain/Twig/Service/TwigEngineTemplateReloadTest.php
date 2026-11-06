<?php

declare(strict_types=1);

/**
 * With Development Mode off the compiled templates live on disk. A saved
 * template has to reach the next request all the same, and a saved layout or
 * partial has to reach the pages built on it. Each render here is a process of
 * its own, like a request.
 */
beforeEach(function (): void {
	$this->dir       = tcmsTestTempDir('twig-reload');
	$this->templates = $this->dir . '/templates';
	$this->cache     = $this->dir . '/cache';
	mkdir($this->templates, 0777, true);
	mkdir($this->cache, 0777, true);

	file_put_contents($this->templates . '/layout.twig', 'layout-1 {% block body %}{% endblock %}');
	file_put_contents($this->templates . '/partial.twig', 'partial-1');
	file_put_contents($this->templates . '/page.twig', '{% extends "@reload/layout.twig" %}{% block body %}page-1 {% include "@reload/partial.twig" %}{% endblock %}');
});

afterEach(function (): void {
	recursiveDelete($this->dir);
});

function renderInOwnProcess(string $templates, string $cache): string
{
	$command = [PHP_BINARY, dirname(__DIR__, 4) . '/fixtures/twig/render-once.php', $templates, $cache, 'page.twig'];

	$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	expect($process)->not->toBeFalse();

	$output = (string)stream_get_contents($pipes[1]);
	$errors = (string)stream_get_contents($pipes[2]);
	$status = proc_close($process);

	expect($status)->toBe(0, $errors);

	return $output;
}

test('a warm cache serves the compiled templates', function (): void {
	expect(renderInOwnProcess($this->templates, $this->cache))->toBe('layout-1 page-1 partial-1')
		->and(renderInOwnProcess($this->templates, $this->cache))->toBe('layout-1 page-1 partial-1');
});

test('a saved page reaches the next request', function (): void {
	renderInOwnProcess($this->templates, $this->cache);

	file_put_contents($this->templates . '/page.twig', '{% extends "@reload/layout.twig" %}{% block body %}page-2 {% include "@reload/partial.twig" %}{% endblock %}');

	expect(renderInOwnProcess($this->templates, $this->cache))->toBe('layout-1 page-2 partial-1');
});

test('a saved layout reaches the pages that extend it', function (): void {
	renderInOwnProcess($this->templates, $this->cache);

	file_put_contents($this->templates . '/layout.twig', 'layout-2 {% block body %}{% endblock %}');

	expect(renderInOwnProcess($this->templates, $this->cache))->toBe('layout-2 page-1 partial-1');
});

test('a saved partial reaches the pages that include it', function (): void {
	renderInOwnProcess($this->templates, $this->cache);

	file_put_contents($this->templates . '/partial.twig', 'partial-2');

	expect(renderInOwnProcess($this->templates, $this->cache))->toBe('layout-1 page-1 partial-2');
});
