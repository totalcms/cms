<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\PageMiddleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\PageMiddleware\PageAuthMiddleware;
use TotalCMS\Support\Config;

final class PageAuthMiddlewareTest extends TestCase
{
	private AccessManager&MockObject $accessManager;
	private Config $config;
	private PageAuthMiddleware $middleware;
	private Psr17Factory $psr17;

	protected function setUp(): void
	{
		$this->accessManager = $this->createMock(AccessManager::class);

		$this->config      = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->api = 'https://example.test';

		$this->middleware = new PageAuthMiddleware($this->accessManager, $this->config);
		$this->psr17      = new Psr17Factory();
	}

	public function testReturnsNullWhenLoggedIn(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(true);

		$this->assertNull($this->middleware->handle(
			$this->psr17->createServerRequest('GET', '/members'),
			$this->page('members'),
		));
	}

	public function testRedirectsBrowserToLoginWhenNotLoggedIn(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(false);

		$response = $this->middleware->handle(
			$this->psr17->createServerRequest('GET', 'https://example.test/members'),
			$this->page('members'),
		);

		$this->assertNotNull($response);
		$this->assertSame(302, $response->getStatusCode());
		$location = $response->getHeaderLine('Location');
		$this->assertStringStartsWith('https://example.test/admin/login?', $location);
		// Original URL is preserved as redirect= so the user lands back here.
		$this->assertStringContainsString('redirect=', $location);
		$this->assertStringContainsString('members', $location);
	}

	public function testReturnsJson401ForJsonAcceptHeader(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(false);

		$request = $this->psr17->createServerRequest('GET', '/members')
			->withHeader('Accept', 'application/json');

		$response = $this->middleware->handle($request, $this->page('members'));

		$this->assertNotNull($response);
		$this->assertSame(401, $response->getStatusCode());
		$this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
		$this->assertSame('', $response->getHeaderLine('Location'));

		/** @var array<string,mixed> $body */
		$body = json_decode((string)$response->getBody(), true);
		$this->assertSame(['error' => 'Authentication required'], $body);
	}

	public function testReturnsJson401ForFormatJsonQueryParam(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(false);

		$request = $this->psr17->createServerRequest('GET', '/members?_format=json');

		$response = $this->middleware->handle($request, $this->page('members'));

		$this->assertNotNull($response);
		$this->assertSame(401, $response->getStatusCode());
	}

	public function testRedirectAddsRedirectParamWithExistingQueryString(): void
	{
		// Login URL is hardcoded as /admin/login (no `?` in the base) so this
		// is more about confirming the URL builder uses the request URI as-is.
		$this->accessManager->method('sessionHasUser')->willReturn(false);

		$request  = $this->psr17->createServerRequest('GET', 'https://example.test/members?ref=email');
		$response = $this->middleware->handle($request, $this->page('members'));

		$this->assertNotNull($response);
		$location = $response->getHeaderLine('Location');
		$this->assertStringContainsString('redirect=', $location);
		// The original ?ref=email travels along inside the redirect param.
		$this->assertStringContainsString('ref%3Demail', $location);
	}

	// --- accessGroups ---

	public function testLoggedInPassesWhenAccessGroupsEmpty(): void
	{
		// No access groups configured = "any login passes".
		// userHasAccess should NOT be called — sessionHasUser is enough.
		$this->accessManager->method('sessionHasUser')->willReturn(true);
		$this->accessManager->expects($this->never())->method('userHasAccess');

		$this->assertNull($this->middleware->handle(
			$this->psr17->createServerRequest('GET', '/members'),
			new PageData(['id' => 'members', 'accessGroups' => []]),
		));
	}

	public function testLoggedInUserInRequiredGroupPasses(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(true);
		$this->accessManager->expects($this->once())
			->method('userHasAccess')
			->with(['staff'])
			->willReturn(true);

		$this->assertNull($this->middleware->handle(
			$this->psr17->createServerRequest('GET', '/members'),
			new PageData(['id' => 'members', 'accessGroups' => ['staff']]),
		));
	}

	public function testLoggedInUserNotInRequiredGroupGets403(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(true);
		$this->accessManager->method('userHasAccess')->willReturn(false);

		$response = $this->middleware->handle(
			$this->psr17->createServerRequest('GET', '/members'),
			new PageData(['id' => 'members', 'accessGroups' => ['staff']]),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
		// No login redirect — they're already logged in. Sending them to login
		// would loop indefinitely.
		$this->assertSame('', $response->getHeaderLine('Location'));
		$this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
		$this->assertSame('403 Forbidden', (string)$response->getBody());
	}

	public function testLoggedInJsonRequestNotInRequiredGroupGets403Json(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(true);
		$this->accessManager->method('userHasAccess')->willReturn(false);

		$request = $this->psr17->createServerRequest('GET', '/members')
			->withHeader('Accept', 'application/json');

		$response = $this->middleware->handle(
			$request,
			new PageData(['id' => 'members', 'accessGroups' => ['staff']]),
		);

		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatusCode());
		$this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));

		/** @var array<string,mixed> $body */
		$body = json_decode((string)$response->getBody(), true);
		$this->assertSame(['error' => 'Forbidden'], $body);
	}

	public function testNotLoggedInWithAccessGroupsStillRedirectsToLogin(): void
	{
		// Group restriction doesn't change the "not logged in" behavior —
		// you still get sent to login first.
		$this->accessManager->method('sessionHasUser')->willReturn(false);
		$this->accessManager->expects($this->never())->method('userHasAccess');

		$response = $this->middleware->handle(
			$this->psr17->createServerRequest('GET', 'https://example.test/members'),
			new PageData(['id' => 'members', 'accessGroups' => ['staff']]),
		);

		$this->assertNotNull($response);
		$this->assertSame(302, $response->getStatusCode());
		$this->assertStringContainsString('/admin/login', $response->getHeaderLine('Location'));
	}

	private function page(string $id): PageData
	{
		return new PageData(['id' => $id]);
	}
}
