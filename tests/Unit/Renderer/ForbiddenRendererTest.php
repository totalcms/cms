<?php

declare(strict_types=1);

namespace Tests\Unit\Renderer;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Renderer\ForbiddenRenderer;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;

// One place decides whether a denied request came from the admin UI (render
// the access-denied page) or from the API (JSON 403). Three middlewares each
// carried their own copy of that decision, and every copy compared the raw
// request path — so on a sub-folder install (/site/admin/...) an admin page
// denial came back as JSON.
final class ForbiddenRendererTest extends TestCase
{
	private function request(string $path, ?string $basePath = null): ServerRequestInterface
	{
		$request = (new Psr17Factory())->createServerRequest('GET', 'https://example.test' . $path);

		return $basePath === null ? $request : $request->withAttribute(RouteContext::BASE_PATH, $basePath);
	}

	public function testAnAdminPathAtTheRootIsTheAdminUi(): void
	{
		$this->assertTrue(ForbiddenRenderer::isAdminUi($this->request('/admin/collections')));
		$this->assertFalse(ForbiddenRenderer::isAdminUi($this->request('/api/collections')));
	}

	public function testTheBasePathIsStrippedBeforeDeciding(): void
	{
		$this->assertTrue(ForbiddenRenderer::isAdminUi($this->request('/site/admin/collections', '/site')));
		$this->assertFalse(ForbiddenRenderer::isAdminUi($this->request('/site/api/collections', '/site')));
	}

	public function testAdminRequestsGetTheAccessDeniedPage(): void
	{
		$factory  = new Psr17Factory();
		$json     = $this->createMock(JsonRenderer::class);
		$twig     = $this->createMock(TwigRenderer::class);
		$rendered = $factory->createResponse(403);

		$json->expects($this->never())->method('json');
		$twig->expects($this->once())
			->method('template')
			->with(
				$this->callback(static fn ($response): bool => $response->getStatusCode() === 403),
				'access-denied.twig',
				['message' => 'No.', 'details' => 'why', 'referrer' => 'https://example.test/admin/'],
			)
			->willReturn($rendered);

		$request = $this->request('/site/admin/x', '/site')->withHeader('Referer', 'https://example.test/admin/');

		$this->assertSame($rendered, (new ForbiddenRenderer($twig, $json, $factory))->forbidden($request, 'No.', 'why'));
	}

	public function testApiRequestsGetJson(): void
	{
		$factory  = new Psr17Factory();
		$json     = $this->createMock(JsonRenderer::class);
		$twig     = $this->createMock(TwigRenderer::class);
		$rendered = $factory->createResponse(403);

		$twig->expects($this->never())->method('template');
		$json->expects($this->once())
			->method('json')
			->with(
				$this->callback(static fn ($response): bool => $response->getStatusCode() === 403),
				['error' => ['message' => 'No.']],
			)
			->willReturn($rendered);

		$this->assertSame($rendered, (new ForbiddenRenderer($twig, $json, $factory))->forbidden($this->request('/api/x'), 'No.', 'ignored for JSON'));
	}
}
