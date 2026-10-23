<?php

declare(strict_types=1);

use TotalCMS\Domain\Builder\Exception\PageNotFoundException;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\ContainerFactory;

/**
 * TwigEngine::render() turns every template exception into an inline
 * `.cms-twig-error` block, so without a carve-out `cms.notFound()` reached
 * the visitor as that error block with a 200. The not-found must escape
 * render(), unwrapped from Twig's RuntimeError, for PageRouterMiddleware to
 * answer with the 404 page. Rendered through the real engine and the real
 * render() — the middleware's own tests mock the engine.
 */
function notFoundTwig(): TwigEngine
{
	static $twig = null;
	if ($twig instanceof TwigEngine) {
		return $twig;
	}

	$dir = sys_get_temp_dir() . '/tcms-notfound-' . uniqid();
	mkdir($dir);
	file_put_contents($dir . '/guard.twig', 'BEFORE {% if object.draft %}{{ cms.notFound() }}{% endif %} AFTER');
	file_put_contents($dir . '/inner.twig', '{{ cms.notFound() }}');
	file_put_contents($dir . '/outer.twig', '{% include "@notfound/inner.twig" %}');

	$twig = ContainerFactory::build()->get(TwigEngine::class);
	$twig->addExtensionTemplatePath($dir, 'notfound');

	return $twig;
}

test('cms.notFound() escapes render() as PageNotFoundException', function (): void {
	expect(fn () => notFoundTwig()->render('@notfound/guard.twig', ['object' => ['draft' => true]]))
		->toThrow(PageNotFoundException::class);
});

test('cms.notFound() inside an include escapes render() too', function (): void {
	// Twig wraps once per include level; the engine must look through them all.
	expect(fn () => notFoundTwig()->render('@notfound/outer.twig'))
		->toThrow(PageNotFoundException::class);
});

test('a template that does not call cms.notFound() renders', function (): void {
	expect(notFoundTwig()->render('@notfound/guard.twig', ['object' => ['draft' => false]]))
		->toBe('BEFORE  AFTER');
});

test('other template errors still render the error block', function (): void {
	expect(notFoundTwig()->render('@notfound/does-not-exist.twig'))
		->toContain('cms-twig-error');
});
