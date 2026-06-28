<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use TotalCMS\Domain\Auth\Service\ImpersonationServiceInterface;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Middleware\ImpersonationBannerMiddleware;

final class ImpersonationBannerMiddlewareTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $impersonation;
	private \PHPUnit\Framework\MockObject\MockObject $session;
	private \PHPUnit\Framework\MockObject\MockObject $csrf;
	private \PHPUnit\Framework\MockObject\MockObject $app;

	protected function setUp(): void
	{
		$this->impersonation = $this->createMock(ImpersonationServiceInterface::class);
		$this->session       = $this->createMock(SessionInterface::class);
		$this->csrf          = $this->createMock(CSRFTokenManager::class);
		$this->app           = $this->createMock(App::class);

		$this->csrf->method('getTokenField')
			->willReturn('<input type="hidden" name="csrf_token" value="testtoken" />');
	}

	private function makeMiddleware(string $basePath = ''): ImpersonationBannerMiddleware
	{
		$this->app->method('getBasePath')->willReturn($basePath);

		/** @var App<\Psr\Container\ContainerInterface> $app */
		$app = $this->app;

		return new ImpersonationBannerMiddleware(
			$this->impersonation,
			$this->session,
			$this->csrf,
			$app,
		);
	}

	private function makeHandler(string $html, string $contentType = 'text/html; charset=utf-8'): RequestHandlerInterface
	{
		$factory  = new Psr17Factory();
		$stream   = $factory->createStream($html);
		$response = $factory->createResponse(200)
			->withHeader('Content-Type', $contentType)
			->withBody($stream);

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($response);

		return $handler;
	}

	private function makeRequest(): ServerRequestInterface
	{
		return $this->createMock(ServerRequestInterface::class);
	}

	public function testInjectsBannerWhenImpersonatingHtmlResponse(): void
	{
		$this->impersonation->method('isImpersonating')->willReturn(true);
		$this->session->method('get')
			->with(SessionKeys::AUTH_USER)
			->willReturn('alice');

		$mw      = $this->makeMiddleware();
		$handler = $this->makeHandler('<html><body>hi</body></html>');
		$result  = $mw->process($this->makeRequest(), $handler);

		$body = (string)$result->getBody();
		$this->assertStringContainsString('impersonation-banner', $body);
		$this->assertStringContainsString('/admin/impersonate/stop', $body);
		$this->assertStringContainsString('alice', $body);
		$this->assertStringContainsString('Return to your account', $body);
		$this->assertStringContainsString('csrf_token', $body);
	}

	public function testBannerInsertedBeforeClosingBodyTag(): void
	{
		$this->impersonation->method('isImpersonating')->willReturn(true);
		$this->session->method('get')->willReturn('bob');

		$mw      = $this->makeMiddleware();
		$handler = $this->makeHandler('<html><body>content</body></html>');
		$result  = $mw->process($this->makeRequest(), $handler);

		$body = (string)$result->getBody();
		// Banner must appear before </body>
		$bannerPos = strpos($body, 'impersonation-banner');
		$bodyClose = strpos($body, '</body>');
		$this->assertNotFalse($bannerPos);
		$this->assertNotFalse($bodyClose);
		$this->assertLessThan($bodyClose, $bannerPos);
	}

	public function testDoesNotInjectWhenNotImpersonating(): void
	{
		$this->impersonation->method('isImpersonating')->willReturn(false);
		$this->session->expects($this->never())->method('get');

		$mw      = $this->makeMiddleware();
		$handler = $this->makeHandler('<html><body>hi</body></html>');
		$result  = $mw->process($this->makeRequest(), $handler);

		$body = (string)$result->getBody();
		$this->assertStringNotContainsString('impersonation-banner', $body);
	}

	public function testDoesNotInjectForNonHtmlResponse(): void
	{
		$this->impersonation->method('isImpersonating')->willReturn(true);

		$mw      = $this->makeMiddleware();
		$handler = $this->makeHandler('{"key":"value"}', 'application/json');
		$result  = $mw->process($this->makeRequest(), $handler);

		$body = (string)$result->getBody();
		$this->assertStringNotContainsString('impersonation-banner', $body);
	}

	public function testSkipsResponsesWithoutClosingBodyTag(): void
	{
		// A response with no </body> (e.g. an HTMX partial) is not a full page —
		// injecting would duplicate the banner into every fragment swap.
		$this->impersonation->method('isImpersonating')->willReturn(true);
		$this->session->method('get')->willReturn('charlie');

		$mw      = $this->makeMiddleware();
		$handler = $this->makeHandler('<div>partial html</div>');
		$result  = $mw->process($this->makeRequest(), $handler);

		$body = (string)$result->getBody();
		$this->assertStringNotContainsString('impersonation-banner', $body);
		$this->assertSame('<div>partial html</div>', $body);
	}

	public function testInjectsWhenContentTypeUnsetButBodyIsHtml(): void
	{
		// Admin/Twig responses often leave Content-Type unset (PHP's default_mimetype
		// supplies text/html at emit time); the banner must still inject.
		$this->impersonation->method('isImpersonating')->willReturn(true);
		$this->session->method('get')->willReturn('dana');

		$mw      = $this->makeMiddleware();
		$handler = $this->makeHandler('<html><body>admin</body></html>', '');
		$result  = $mw->process($this->makeRequest(), $handler);

		$this->assertStringContainsString('impersonation-banner', (string)$result->getBody());
	}

	public function testBasePathPrependedToStopUrl(): void
	{
		$this->impersonation->method('isImpersonating')->willReturn(true);
		$this->session->method('get')->willReturn('dave');

		$mw      = $this->makeMiddleware('/mysite');
		$handler = $this->makeHandler('<html><body>page</body></html>');
		$result  = $mw->process($this->makeRequest(), $handler);

		$this->assertStringContainsString('/mysite/admin/impersonate/stop', (string)$result->getBody());
	}

	public function testShowsFallbackLabelWhenUserIdIsEmpty(): void
	{
		$this->impersonation->method('isImpersonating')->willReturn(true);
		$this->session->method('get')->willReturn(null);

		$mw      = $this->makeMiddleware();
		$handler = $this->makeHandler('<html><body>page</body></html>');
		$result  = $mw->process($this->makeRequest(), $handler);

		$this->assertStringContainsString('another user', (string)$result->getBody());
	}
}
