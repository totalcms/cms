<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Extension;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use TotalCMS\Action\Extension\ExtensionAdminRouteAction;
use TotalCMS\Action\Extension\ExtensionRouteAction;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Extension\Data\ExtensionRoute;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Security\CSRF\CSRFRequestValidator;
use TotalCMS\Renderer\JsonRenderer;

/**
 * The API and admin dispatchers share the resolve-and-invoke body; only the
 * matcher and, for API routes, the credential check differ.
 */
final class ExtensionRouteActionsTest extends TestCase
{
	private ExtensionManager&MockObject $extensions;
	private AccessManager&MockObject $access;
	private ApiKeyAuthenticator&MockObject $apiKeys;
	private CSRFRequestValidator&MockObject $csrf;

	protected function setUp(): void
	{
		$this->extensions = $this->createMock(ExtensionManager::class);
		$this->access     = $this->createMock(AccessManager::class);
		$this->apiKeys    = $this->createMock(ApiKeyAuthenticator::class);
		$this->csrf       = $this->createMock(CSRFRequestValidator::class);
	}

	private function renderer(): JsonRenderer
	{
		$renderer = $this->createMock(JsonRenderer::class);
		$renderer->method('json')->willReturnCallback(
			static fn (ResponseInterface $response, array $data, int $status = 0): ResponseInterface => ($status > 0 ? $response->withStatus($status) : $response)->withHeader('X-Error', (string)($data['error'] ?? ''))
		);

		return $renderer;
	}

	private function apiAction(): ExtensionRouteAction
	{
		return new ExtensionRouteAction($this->extensions, $this->access, $this->apiKeys, $this->createMock(ContainerInterface::class), $this->renderer(), $this->csrf);
	}

	private function adminAction(): ExtensionAdminRouteAction
	{
		return new ExtensionAdminRouteAction($this->extensions, $this->createMock(ContainerInterface::class), $this->renderer());
	}

	private function request(string $method = 'GET'): ServerRequestInterface
	{
		return (new ServerRequestFactory())->createServerRequest($method, '/ext/acme/widgets/s/1');
	}

	/** @return array<string,string> */
	private function args(): array
	{
		return ['vendor' => 'acme', 'name' => 'widgets', 'path' => 's/1'];
	}

	private function handlerRoute(bool $public): ExtensionRoute
	{
		return new ExtensionRoute(
			static fn (ServerRequestInterface $r, ResponseInterface $res, array $args): ResponseInterface => $res->withHeader('X-Handled', implode(',', array_keys($args))),
			$public,
			'any',
			['id' => '1'],
		);
	}

	public function testADisabledExtensionIs404OnBothDispatchers(): void
	{
		$this->extensions->method('isEnabled')->willReturn(false);

		$this->assertSame(404, ($this->apiAction())($this->request(), new Response(), $this->args())->getStatusCode());
		$this->assertSame(404, ($this->adminAction())($this->request(), new Response(), $this->args())->getStatusCode());
	}

	public function testAnUnmatchedPathIs404(): void
	{
		$this->extensions->method('isEnabled')->willReturn(true);
		$this->extensions->method('matchExtensionAdminRoute')->willReturn(null);

		$response = ($this->adminAction())($this->request(), new Response(), $this->args());

		$this->assertSame(404, $response->getStatusCode());
		$this->assertSame('Route not found', $response->getHeaderLine('X-Error'));
	}

	public function testTheAdminDispatcherInvokesTheHandlerWithRouteParamsMergedIn(): void
	{
		$this->extensions->method('isEnabled')->willReturn(true);
		$this->extensions->method('matchExtensionAdminRoute')->with('acme/widgets', 'GET', '/s/1')->willReturn($this->handlerRoute(false));

		$response = ($this->adminAction())($this->request(), new Response(), $this->args());

		$this->assertSame('vendor,name,path,id', $response->getHeaderLine('X-Handled'));
	}

	public function testAPublicApiRouteNeedsNoCredentials(): void
	{
		$this->extensions->method('isEnabled')->willReturn(true);
		$this->extensions->method('matchExtensionRoute')->willReturn($this->handlerRoute(true));
		$this->access->expects($this->never())->method('sessionHasUser');

		$response = ($this->apiAction())($this->request(), new Response(), $this->args());

		$this->assertSame('vendor,name,path,id', $response->getHeaderLine('X-Handled'));
	}

	public function testAPrivateApiRouteWithoutASessionOrKeyIs401(): void
	{
		$this->extensions->method('isEnabled')->willReturn(true);
		$this->extensions->method('matchExtensionRoute')->willReturn($this->handlerRoute(false));
		$this->access->method('sessionHasUser')->willReturn(false);
		$this->apiKeys->method('authenticate')->willReturn(null);

		$this->assertSame(401, ($this->apiAction())($this->request(), new Response(), $this->args())->getStatusCode());
	}

	public function testASessionWriteWithoutCsrfProofIs403(): void
	{
		$this->extensions->method('isEnabled')->willReturn(true);
		$this->extensions->method('matchExtensionRoute')->willReturn($this->handlerRoute(false));
		$this->access->method('sessionHasUser')->willReturn(true);
		$this->csrf->method('passes')->willReturn(false);

		$this->assertSame(403, ($this->apiAction())($this->request('POST'), new Response(), $this->args())->getStatusCode());
	}
}
