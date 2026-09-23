<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth\Service;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use TotalCMS\Domain\Auth\Service\LoginRedirector;
use TotalCMS\Domain\Session\SessionKeys;

// Sending an unauthenticated visitor to the login (or denied) page remembers
// where they were so they can be returned there afterwards. Both auth
// middlewares carried a copy of this.
final class LoginRedirectorTest extends TestCase
{
	public function testStashesTheOriginAndRedirectsToTheNamedRoute(): void
	{
		$factory = new Psr17Factory();
		$parser  = $this->createMock(RouteParserInterface::class);
		$parser->method('urlFor')->with('login')->willReturn('/admin/login');

		$request = $factory->createServerRequest('GET', 'https://example.test/admin/collections?x=1')
			->withHeader('Referer', 'https://example.test/admin/')
			->withAttribute(RouteContext::ROUTE_PARSER, $parser)
			->withAttribute(RouteContext::ROUTING_RESULTS, $this->createMock(RoutingResults::class));

		$session = new InMemorySession();

		$response = (new LoginRedirector($session, $factory))->toRoute($request, 'login');

		$this->assertSame(302, $response->getStatusCode());
		$this->assertSame('/admin/login', $response->getHeaderLine('Location'));
		$this->assertSame('https://example.test/admin/collections?x=1', $session->get(SessionKeys::REQUEST_ORIGIN_URL));
		$this->assertSame('https://example.test/admin/', $session->get(SessionKeys::REQUEST_REFERER_URL));
	}
}
