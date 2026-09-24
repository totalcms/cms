<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Builder\Data\RouteMatch;
use TotalCMS\Domain\Builder\Service\PageInspectorRenderer;
use TotalCMS\Support\Config;

/**
 * Unit tests for PageInspectorRenderer.
 *
 * The renderer is gated to signed-in users who may edit pages (super admins
 * and groups with the Site Builder permission) and not-yet-dismissed visitors. These tests cover both the gating logic and the snippet
 * structure for builder-page and collection-URL renders.
 */
final class PageInspectorRendererTest extends TestCase
{
	private AccessManager&MockObject $accessManager;
	private AccessControlService&MockObject $accessControl;
	private Config $config;
	private PageInspectorRenderer $renderer;

	protected function setUp(): void
	{
		$this->accessManager = $this->createMock(AccessManager::class);
		$this->accessControl = $this->createMock(AccessControlService::class);
		$this->config        = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->api   = 'https://example.test';
		$this->renderer      = new PageInspectorRenderer($this->accessManager, $this->accessControl, $this->config);
	}

	public function testReturnsBodyUnchangedWhenNotLoggedIn(): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(false);

		$body  = '<html><body>hi</body></html>';
		$match = $this->builderPageMatch();

		$this->assertSame(
			$body,
			$this->renderer->maybeInject($body, $this->request(), $match),
		);
	}

	public function testReturnsBodyUnchangedWhenDismissCookieIsSet(): void
	{
		$this->signedIn();

		$body    = '<html><body>hi</body></html>';
		$match   = $this->builderPageMatch();
		$request = $this->request()->withCookieParams(['tcms_inspector_hidden' => '1']);

		$this->assertSame(
			$body,
			$this->renderer->maybeInject($body, $request, $match),
		);
	}

	public function testInjectsBeforeClosingBodyTag(): void
	{
		$this->signedIn();

		$body   = '<html><body>hi</body></html>';
		$result = $this->renderer->maybeInject($body, $this->request(), $this->builderPageMatch());

		// Snippet is injected immediately before </body>, not appended after.
		$this->assertStringContainsString('id="t3-inspector"', $result);
		$this->assertMatchesRegularExpression('#id="t3-inspector".*</body></html>$#s', $result);
		$this->assertStringStartsWith('<html><body>hi', $result);
	}

	public function testInjectsBeforeLastBodyTagWhenContentContainsLiteralBodyTag(): void
	{
		// A page that displays HTML markup as literal content (e.g. a "what's
		// in this email" preview) might have `</body>` inside its content.
		// The inspector must not split that — it must inject before the
		// REAL last </body>.
		$this->signedIn();

		$body   = '<html><body><pre>example: &lt;/body&gt;</pre><p>real content</p></body></html>';
		$result = $this->renderer->maybeInject($body, $this->request(), $this->builderPageMatch());

		// The inspector should be just before the closing </body>, after `real content`.
		$this->assertStringContainsString('real content</p><aside id="t3-inspector"', $result);
		$this->assertStringEndsWith('</body></html>', $result);
	}

	public function testFallsBackToAppendingWhenNoBodyTagPresent(): void
	{
		// HTML fragments without a body tag still get the chip — better to
		// surface it somewhere than nowhere.
		$this->signedIn();

		$body   = '<div>fragment</div>';
		$result = $this->renderer->maybeInject($body, $this->request(), $this->builderPageMatch());

		$this->assertStringStartsWith('<div>fragment</div>', $result);
		$this->assertStringContainsString('id="t3-inspector"', $result);
	}

	public function testBuilderPageSnippetIncludesPageEditUrl(): void
	{
		$this->signedIn();

		$result = $this->renderer->maybeInject(
			'<body></body>',
			$this->request(),
			$this->builderPageMatch(['id' => 'about', 'title' => 'About']),
		);

		$this->assertStringContainsString('https://example.test/admin/builder/page/about', $result);
		$this->assertStringContainsString('Edit page', $result);
	}

	public function testCollectionMatchSnippetIncludesObjectEditUrl(): void
	{
		$this->signedIn();

		$match = new RouteMatch(
			template: 'pages/post.twig',
			pageData: ['id' => 'hello-world', 'title' => 'Hello'],
			params: ['slug' => 'hello-world'],
			collection: 'blog',
		);

		$result = $this->renderer->maybeInject('<body></body>', $this->request(), $match);

		$this->assertStringContainsString('https://example.test/admin/collections/blog/hello-world', $result);
		$this->assertStringContainsString('Edit object', $result);
		$this->assertStringContainsString('collection (blog)', $result);
	}

	public function testSnippetIncludesActiveFeaturesForBuilderPages(): void
	{
		$this->signedIn();

		$result = $this->renderer->maybeInject(
			'<body></body>',
			$this->request(),
			$this->builderPageMatch([
				'id'         => 'about',
				'title'      => 'About',
				'middleware' => ['ab-split', 'geo-redirect'],
			]),
		);

		$this->assertStringContainsString('Features', $result);
		$this->assertStringContainsString('ab-split, geo-redirect', $result);
	}

	public function testSnippetEscapesUserContent(): void
	{
		$this->signedIn();

		$result = $this->renderer->maybeInject(
			'<body></body>',
			$this->request(),
			$this->builderPageMatch([
				'id'    => 'xss',
				'title' => '<script>alert(1)</script>',
			]),
		);

		$this->assertStringNotContainsString('<script>alert(1)', $result);
		$this->assertStringContainsString('&lt;script&gt;alert(1)', $result);
	}

	public function testReturnsBodyUnchangedForASignedInUserWhoCannotEditPages(): void
	{
		// A member of a portal auth collection is a session user, but the
		// chip names routes and templates and links into the admin: it is
		// for people who edit pages, not for everyone who can sign in.
		$this->signedIn(canEditPages: false);

		$body = '<html><body>hi</body></html>';

		$this->assertSame(
			$body,
			$this->renderer->maybeInject($body, $this->request(), $this->builderPageMatch()),
		);
	}

	public function testReturnsBodyUnchangedWhenTheSessionUserNoLongerValidates(): void
	{
		// userData() is empty for a session naming a deleted user.
		$this->accessManager->method('sessionHasUser')->willReturn(true);
		$this->accessManager->method('userData')->willReturn([]);
		$this->accessControl->expects($this->never())->method('canAccessBuilder');

		$body = '<html><body>hi</body></html>';

		$this->assertSame(
			$body,
			$this->renderer->maybeInject($body, $this->request(), $this->builderPageMatch()),
		);
	}

	public function testInjectsForASignedInUserWhoCanEditPages(): void
	{
		$this->signedIn(canEditPages: true);

		$result = $this->renderer->maybeInject('<html><body>hi</body></html>', $this->request(), $this->builderPageMatch());

		$this->assertStringContainsString('id="t3-inspector"', $result);
	}

	/**
	 * A signed-in user. canEditPages is the answer to canAccessBuilder(),
	 * which super admins always pass and which a group grants through the
	 * Site Builder permission.
	 */
	private function signedIn(bool $canEditPages = true): void
	{
		$this->accessManager->method('sessionHasUser')->willReturn(true);
		$this->accessManager->method('userData')->willReturn(['id' => 'editor-1', 'collection' => 'auth']);
		$this->accessControl->method('canAccessBuilder')->with('editor-1')->willReturn($canEditPages);
	}

	private function request(): ServerRequestInterface
	{
		return (new Psr17Factory())->createServerRequest('GET', '/about');
	}

	/** @param array<string,mixed> $overrides */
	private function builderPageMatch(array $overrides = []): RouteMatch
	{
		$pageData = array_merge([
			'id'    => 'about',
			'title' => 'About',
			'route' => '/about',
		], $overrides);

		return new RouteMatch(
			template: 'pages/about.twig',
			pageData: $pageData,
			params: [],
		);
	}
}
