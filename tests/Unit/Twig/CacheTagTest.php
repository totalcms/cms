<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Extension\CacheTokenParser;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

/**
 * A duck-typed FragmentCache stand-in. The compiled node resolves the runtime
 * by class name and calls render(); it does not need a real FragmentCache
 * (which is final). Records the parsed args and runs the body.
 */
function fakeFragmentCacheEnv(object $fragmentCache, array $templates): Environment
{
	$twig = new Environment(new ArrayLoader($templates), [
		'autoescape' => false,
		'cache'      => false,
		'use_yield'  => true,
	]);
	$twig->addTokenParser(new CacheTokenParser());
	$twig->addRuntimeLoader(new class($fragmentCache) implements RuntimeLoaderInterface {
		public function __construct(private readonly object $fc)
		{
		}

		public function load(string $class): ?object
		{
			return $class === TotalCMS\Domain\Cache\FragmentCache::class ? $this->fc : null;
		}
	});

	return $twig;
}

function recordingFragmentCache(array &$captured): object
{
	return new class($captured) {
		/** @var array<int,array<string,mixed>> */
		public array $captured;

		public function __construct(array &$c)
		{
			$this->captured = &$c;
		}

		public function render(string $key, ?int $ttl, array $tags, bool $shared, Closure $body): string
		{
			$this->captured[] = compact('key', 'ttl', 'tags', 'shared');

			return $body();
		}
	};
}

test('cache tag renders body and routes parsed args through FragmentCache::render', function (): void {
	$captured = [];
	$fc       = recordingFragmentCache($captured);
	$twig     = fakeFragmentCacheEnv($fc, [
		't' => "{% cache 'side:' ~ id ttl=1800 tags=['blog'] shared=true %}<b>{{ id }}</b>{% endcache %}",
	]);

	$out = $twig->render('t', ['id' => 42]);

	expect($out)->toBe('<b>42</b>');
	expect($captured[0]['key'])->toBe('side:42');
	expect($captured[0]['ttl'])->toBe(1800);
	expect($captured[0]['tags'])->toBe(['blog']);
	expect($captured[0]['shared'])->toBeTrue();
});

test('cache tag with only a key defaults ttl=null, tags=[], shared=false', function (): void {
	$captured = [];
	$fc       = recordingFragmentCache($captured);
	$twig     = fakeFragmentCacheEnv($fc, ['t' => "{% cache 'k' %}X{% endcache %}"]);

	$twig->render('t', []);

	expect($captured[0])->toBe(['key' => 'k', 'ttl' => null, 'tags' => [], 'shared' => false]);
});

test('cache tag rejects an unknown option', function (): void {
	$discard = [];
	$twig    = fakeFragmentCacheEnv(recordingFragmentCache($discard), ['t' => "{% cache 'k' bogus=1 %}X{% endcache %}"]);

	expect(fn () => $twig->render('t', []))->toThrow(Twig\Error\SyntaxError::class);
});
