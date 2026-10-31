<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Service\TwigTemplateCache;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * String templates — a Stacks page's whole output buffer, template_from_string()
 * — are compiled into their own folder and pruned once unused for a day, so a
 * page holding a per-request value no longer leaves one compiled copy per
 * visit behind forever.
 */
beforeEach(function (): void {
	$this->dir   = tcmsTestTempDir('twig-template-cache');
	$this->cache = new TwigTemplateCache($this->dir);

	// A unique source per test: Twig skips compiling a class already loaded
	// in this process, so a repeated source would never reach the cache.
	$this->twig = new Environment(new ArrayLoader(['page.twig' => 'File {{ n }}{# ' . $this->dir . ' #}']), ['cache' => $this->cache]);
});

afterEach(function (): void {
	recursiveDelete($this->dir);
});

/** @return list<string> */
function compiledStrings(string $dir): array
{
	return glob($dir . '/' . TwigTemplateCache::STRING_DIR . '/*/*.php') ?: [];
}

test('string templates compile into their own folder, file templates where they always did', function (): void {
	expect($this->twig->createTemplate('Hello {{ n }}')->render(['n' => 1]))->toBe('Hello 1')
		->and($this->twig->render('page.twig', ['n' => 2]))->toBe('File 2')
		->and(compiledStrings($this->dir))->toHaveCount(1)
		->and(glob($this->dir . '/??/*.php') ?: [])->toHaveCount(1);
});

test('names Twig gives string templates are recognized', function (): void {
	expect(TwigTemplateCache::isStringTemplate('__string_template__0123abcd'))->toBeTrue()
		->and(TwigTemplateCache::isStringTemplate('snippet (string template 0123abcd)'))->toBeTrue()
		->and(TwigTemplateCache::isStringTemplate('pages/home.twig'))->toBeFalse();
});

test('prune deletes string templates unused for a day and keeps the rest', function (): void {
	$this->twig->createTemplate('Old {{ n }}');
	$this->twig->createTemplate('Recent {{ n }}');
	$this->twig->render('page.twig', ['n' => 1]);
	$old    = array_values(array_filter(compiledStrings($this->dir), fn (string $f): bool => str_contains((string)file_get_contents($f), 'Old ')))[0];
	$recent = array_values(array_diff(compiledStrings($this->dir), [$old]))[0];
	touch($old, time() - TwigTemplateCache::KEEP - 60);
	$fileTemplate = (glob($this->dir . '/??/*.php') ?: [])[0];
	touch($fileTemplate, time() - TwigTemplateCache::KEEP - 60);

	expect($this->cache->prune())->toBe(1)
		->and(file_exists($old))->toBeFalse()
		->and(file_exists($recent))->toBeTrue()
		->and(file_exists($fileTemplate))->toBeTrue();
});

test('using a copy refreshes its timestamp, so a page in use is never pruned', function (): void {
	$this->twig->createTemplate('Busy {{ n }}');
	$file = compiledStrings($this->dir)[0];
	touch($file, time() - TwigTemplateCache::REFRESH - 60);

	clearstatcache(true, $file);
	$this->cache->load($file);
	clearstatcache(true, $file);

	expect(filemtime($file))->toBeGreaterThan(time() - 60)
		->and($this->cache->prune())->toBe(0);
});

test('writing a string template prunes at most once an hour', function (): void {
	$this->twig->createTemplate('First {{ n }}');
	$first = compiledStrings($this->dir)[0];
	touch($first, time() - TwigTemplateCache::KEEP - 60);

	// The first write already pruned; within the hour the next one does not.
	$this->twig->createTemplate('Second {{ n }}');
	expect(file_exists($first))->toBeTrue();

	touch($this->dir . '/' . TwigTemplateCache::STRING_DIR . '/.pruned', time() - TwigTemplateCache::PRUNE_EVERY - 60);
	$this->twig->createTemplate('Third {{ n }}');

	expect(file_exists($first))->toBeFalse()
		->and(compiledStrings($this->dir))->toHaveCount(2);
});
