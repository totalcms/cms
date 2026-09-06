<?php

declare(strict_types=1);

use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Psr7\Factory\ServerRequestFactory;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Rendering\Service\FragmentRenderer;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * The two gates every fragment shares. Rendering itself is covered by the
 * feature tests against real templates; TwigEngine is readonly and cannot be
 * doubled, so it is constructed empty here and never reached.
 */
function fragmentRenderer(bool $canTemplates): FragmentRenderer
{
	$editions = test()->createMock(EditionFeatureService::class);
	$editions->method('can')->willReturn($canTemplates);

	return new FragmentRenderer((new ReflectionClass(TwigEngine::class))->newInstanceWithoutConstructor(), $editions);
}

function fragmentRequest(array $query = [], array $headers = []): \Psr\Http\Message\ServerRequestInterface
{
	$request = (new ServerRequestFactory())->createServerRequest('GET', '/api/collections/blog/x')->withQueryParams($query);
	foreach ($headers as $name => $value) {
		$request = $request->withHeader($name, $value);
	}

	return $request;
}

describe('FragmentRenderer', function (): void {
	test('refuses a missing template as a bad request', function (): void {
		fragmentRenderer(true)->render(fragmentRequest(), '', []);
	})->throws(HttpBadRequestException::class, 'template');

	test('refuses when the edition has no templates feature', function (): void {
		fragmentRenderer(false)->render(fragmentRequest(), 'blog/card', []);
	})->throws(HttpForbiddenException::class, 'Standard');

	test('wantsHtml recognises format=html and an htmx request naming a template', function (): void {
		expect(FragmentRenderer::wantsHtml(fragmentRequest(['format' => 'html'])))->toBeTrue();
		expect(FragmentRenderer::wantsHtml(fragmentRequest(['template' => 'x'], ['HX-Request' => 'true'])))->toBeTrue();
		expect(FragmentRenderer::wantsHtml(fragmentRequest(['template' => 'x'])))->toBeFalse();
		expect(FragmentRenderer::wantsHtml(fragmentRequest([], ['HX-Request' => 'true'])))->toBeFalse();
		expect(FragmentRenderer::templateFrom(fragmentRequest(['template' => 'forms/thanks'])))->toBe('forms/thanks');
	});
});
