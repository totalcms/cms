<?php

namespace Tests\Unit\Middleware;

use Odan\Session\SessionManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Middleware\LazySessionStartMiddleware;

final class LazySessionStartMiddlewareTest extends TestCase
{
	private MockObject $session;
	private MockObject $handler;
	private LazySessionStartMiddleware $middleware;

	protected function setUp(): void
	{
		$this->session    = $this->createMock(SessionManagerInterface::class);
		$this->handler    = $this->createMock(RequestHandlerInterface::class);
		$this->middleware = new LazySessionStartMiddleware($this->session);
	}

	public function testSkipsSessionForImageworksPath(): void
	{
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($response);

		// The whole point of the perf fix: no session lifecycle on the image path.
		$this->session->expects($this->never())->method('start');
		$this->session->expects($this->never())->method('save');

		$result = $this->middleware->process($this->createRequest('/imageworks/blog/cover.jpg'), $this->handler);

		$this->assertSame($response, $result);
	}

	public function testSkipsSessionForImageworksUnderSubPath(): void
	{
		// This middleware runs outside BasePathMiddleware, so the path can carry
		// a sub-path prefix when T3 is installed in a sub-directory.
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($response);

		$this->session->expects($this->never())->method('start');
		$this->session->expects($this->never())->method('save');

		$this->middleware->process($this->createRequest('/mysite/imageworks/photo.png', '/mysite/index.php'), $this->handler);
	}

	#[DataProvider('objectsNamedLikeRoutes')]
	public function testAnObjectIdThatMatchesARouteKeepsItsSession(string $path, string $scriptName): void
	{
		// The rules match from the start of the route, not anywhere in the URL:
		// an object whose id is `imageworks` lost its session on upload, and the
		// remember-me restore then failed with a 500.
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($response);

		$this->session->method('isStarted')->willReturn(false);
		$this->session->expects($this->once())->method('start');
		$this->session->expects($this->once())->method('save');

		$this->middleware->process($this->createRequest($path, $scriptName), $this->handler);
	}

	/** @return array<string,array{string,string}> */
	public static function objectsNamedLikeRoutes(): array
	{
		return [
			'api upload'           => ['/api/collections/features/imageworks/gallery', '/index.php'],
			'api upload, sub-path' => ['/mysite/api/collections/features/imageworks/gallery', '/mysite/index.php'],
			'admin edit'           => ['/admin/collections/features/imageworks', '/index.php'],
			'api object named api' => ['/api/collections/docs/api/assets/file', '/index.php'],
		];
	}

	#[DataProvider('assetPaths')]
	public function testSkipsSessionForStaticAssets(string $path, string $scriptName = '/index.php'): void
	{
		// A started session sets PHPSESSID on the response, and a CDN will not
		// cache a response carrying Set-Cookie — so the immutable assets were
		// hitting the origin on every first visit.
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($response);

		$this->session->expects($this->never())->method('start');
		$this->session->expects($this->never())->method('save');

		$this->assertSame($response, $this->middleware->process($this->createRequest($path, $scriptName), $this->handler));
	}

	/** @return array<string,array{0:string,1?:string}> */
	public static function assetPaths(): array
	{
		return [
			'core asset'              => ['/api/assets/content.css'],
			'core asset, sub-path'    => ['/mysite/api/assets/icons.css', '/mysite/index.php'],
			'core asset, public dir'  => ['/tcms/api/assets/icons.css', '/tcms/public/index.php'],
			'nested core asset'       => ['/api/assets/build/site.css'],
			'extension asset'         => ['/api/ext/totalcms/docs/assets/docs.js'],
			// A Site Builder page that serves an image (a badge pasted on other
			// sites): one session per view of every page it sits on otherwise.
			'page image'              => ['/badges/pill-light.svg'],
			'page image, dynamic'     => ['/@joeworkman/badge.svg'],
			'page image, sub-path'    => ['/mysite/og/card.png', '/mysite/index.php'],
		];
	}

	public function testStartsSessionForOtherApiPaths(): void
	{
		// Only the asset routes are sessionless; the rest of the API keeps its
		// session (CSRF tokens, login state).
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($response);

		$this->session->method('isStarted')->willReturn(false);
		$this->session->expects($this->once())->method('start');
		$this->session->expects($this->once())->method('save');

		$this->middleware->process($this->createRequest('/api/collections/blog'), $this->handler);
	}

	public function testStartsAndSavesSessionForNormalPath(): void
	{
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($response);

		$this->session->method('isStarted')->willReturn(false);
		$this->session->expects($this->once())->method('start');
		$this->session->expects($this->once())->method('save');

		$result = $this->middleware->process($this->createRequest('/admin/dashboard'), $this->handler);

		$this->assertSame($response, $result);
	}

	public function testDoesNotRestartAnAlreadyStartedSession(): void
	{
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($response);

		$this->session->method('isStarted')->willReturn(true);
		$this->session->expects($this->never())->method('start');
		// save() still runs so the handler's writes are persisted.
		$this->session->expects($this->once())->method('save');

		$this->middleware->process($this->createRequest('/admin/dashboard'), $this->handler);
	}

	public function testDoesNotTreatNonImageworksPathAsSessionless(): void
	{
		// Guard against an over-broad regex: a path that merely contains the
		// substring elsewhere must still get a session.
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($response);

		$this->session->method('isStarted')->willReturn(false);
		$this->session->expects($this->once())->method('start');
		$this->session->expects($this->once())->method('save');

		$this->middleware->process($this->createRequest('/admin/imageworks-settings'), $this->handler);
	}

	#[DataProvider('sessionImagePaths')]
	public function testImageExtensionsUnderAdminOrApiKeepTheirSession(string $path, string $scriptName = '/index.php'): void
	{
		// The image-extension rule is for front-end pages only: the admin and
		// the API check login state on every request, whatever the URL ends in.
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($response);

		$this->session->method('isStarted')->willReturn(false);
		$this->session->expects($this->once())->method('start');
		$this->session->expects($this->once())->method('save');

		$this->middleware->process($this->createRequest($path, $scriptName), $this->handler);
	}

	/** @return array<string,array{0:string,1?:string}> */
	public static function sessionImagePaths(): array
	{
		return [
			'admin'          => ['/admin/media/logo.svg'],
			'api'            => ['/api/collections/svg/logo.svg'],
			'admin sub-path' => ['/mysite/admin/icons/x.png', '/mysite/index.php'],
			'not at the end' => ['/badges/pill.svg/edit'],
		];
	}

	private function createRequest(string $path, string $scriptName = '/index.php'): ServerRequestInterface
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn($path);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);
		$request->method('getServerParams')->willReturn(['SCRIPT_NAME' => $scriptName]);

		return $request;
	}
}
