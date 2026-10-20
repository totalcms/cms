<?php

declare(strict_types=1);

use TotalCMS\Domain\Builder\Exception\PageRedirectException;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\ContainerFactory;

/**
 * `cms.redirect()` must escape TwigEngine::render(), unwrapped from Twig's
 * RuntimeError, for PageRouterMiddleware to answer with the redirect — the
 * same carve-out `cms.notFound()` has. Rendered through the real engine and
 * the real adapter; the middleware's own tests mock the engine.
 */
function redirectTwig(): TwigEngine
{
	static $twig = null;
	if ($twig instanceof TwigEngine) {
		return $twig;
	}

	$dir = sys_get_temp_dir() . '/tcms-redirect-' . uniqid();
	mkdir($dir);
	file_put_contents($dir . '/go.twig', 'BEFORE {{ cms.redirect(url, status) }} AFTER');
	file_put_contents($dir . '/default.twig', '{{ cms.redirect("/@davide?mtm_campaign=badge") }}');
	file_put_contents($dir . '/outer.twig', '{% include "@redirect/default.twig" %}');

	$twig = ContainerFactory::build()->get(TwigEngine::class);
	$twig->addExtensionTemplatePath($dir, 'redirect');

	return $twig;
}

function caughtRedirect(callable $render): ?PageRedirectException
{
	try {
		$render();
	} catch (PageRedirectException $e) {
		return $e;
	}

	return null;
}

test('cms.redirect() escapes render() with its URL and a 302 by default', function (): void {
	$redirect = caughtRedirect(fn () => redirectTwig()->render('@redirect/default.twig'));

	expect($redirect)->toBeInstanceOf(PageRedirectException::class)
		->and($redirect->url)->toBe('/@davide?mtm_campaign=badge')
		->and($redirect->status)->toBe(302);
});

test('cms.redirect() inside an include escapes render() too', function (): void {
	expect(fn () => redirectTwig()->render('@redirect/outer.twig'))
		->toThrow(PageRedirectException::class);
});

test('cms.redirect() keeps a permanent status', function (): void {
	$redirect = caughtRedirect(fn () => redirectTwig()->render('@redirect/go.twig', ['url' => '/new', 'status' => 301]));

	expect($redirect?->status)->toBe(301);
});

test('cms.redirect() turns a status that is not a redirect into 302', function (): void {
	$redirect = caughtRedirect(fn () => redirectTwig()->render('@redirect/go.twig', ['url' => '/x', 'status' => 418]));

	expect($redirect?->status)->toBe(302);
});

test('cms.redirect() refuses a URL that would add headers', function (string $url): void {
	// An InvalidArgumentException is an ordinary template error: the error
	// block renders, and no Location header is ever sent.
	expect(caughtRedirect(fn () => redirectTwig()->render('@redirect/go.twig', ['url' => $url, 'status' => 302])))->toBeNull()
		->and(redirectTwig()->render('@redirect/go.twig', ['url' => $url, 'status' => 302]))->toContain('cms-twig-error');
})->with([
	'CRLF'  => "/x\r\nSet-Cookie: a=b",
	'LF'    => "/x\nSet-Cookie: a=b",
	'empty' => '',
]);
