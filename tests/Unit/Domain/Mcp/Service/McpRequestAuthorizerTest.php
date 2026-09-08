<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Exception\McpAuthException;
use TotalCMS\Domain\Mcp\Auth\Service\McpAuth;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpRequestAuthorizer;
use TotalCMS\Domain\Mcp\Service\McpRequestBody;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\McpUrlBuilder;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthScopeEvaluator;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * The persona → PersonaContext → scope-gate pipeline the endpoint runs before
 * building the server. OAuthScopeEvaluator and OAuthActivityLogger are final
 * and are used for real; everything else is mocked. The 401/403 bodies and
 * headers are asserted byte-for-byte because MCP clients key their UX off
 * them.
 */
final class McpRequestAuthorizerTest extends TestCase
{
	private MockObject $mcpAuth;
	private PersonaContext $personaContext;
	private MockObject $urlBuilder;
	private MockObject $accessControl;
	private OAuthScopeEvaluator $scopeEvaluator;
	private OAuthActivityLogger $activityLogger;

	protected function setUp(): void
	{
		$this->mcpAuth        = $this->createMock(McpAuth::class);
		$this->personaContext = new PersonaContext(
			$this->createStub(CollectionFetcher::class),
			$this->createStub(McpSchemaResolver::class),
		);
		$this->urlBuilder     = $this->createMock(McpUrlBuilder::class);
		$this->urlBuilder->method('protectedResourceMetadataUrl')->willReturn('https://site.test/.well-known/oauth-protected-resource');
		$this->accessControl  = $this->createMock(AccessControlService::class);
		// Final classes: build the real ones. Check their constructors; the
		// evaluator takes the scope registry, the logger a PSR logger — see
		// the report for what each constructor actually needed.
		$this->scopeEvaluator = $this->realScopeEvaluator();
		$this->activityLogger = $this->realActivityLogger();
	}

	private function realScopeEvaluator(): OAuthScopeEvaluator
	{
		return new OAuthScopeEvaluator(new OAuthScopeRegistry());
	}

	private function realActivityLogger(): OAuthActivityLogger
	{
		return new OAuthActivityLogger(new NullLogger());
	}

	private function authorizer(): McpRequestAuthorizer
	{
		$config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth = ['collection' => 'auth'];

		return new McpRequestAuthorizer(
			$this->mcpAuth,
			$this->personaContext,
			$this->scopeEvaluator,
			$this->activityLogger,
			$this->urlBuilder,
			$this->accessControl,
			$config,
			new JsonRenderer(),
		);
	}

	private function request(string $json): ServerRequest
	{
		return new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json'], $json);
	}

	public function testAuthFailureIsA401WithTheReasonInWwwAuthenticate(): void
	{
		$this->mcpAuth->method('resolvePersona')->willThrowException(new McpAuthException('Login required', 'login_required'));
		$request = $this->request('{"method":"initialize"}');

		$result = $this->authorizer()->authorize($request, new Response(), McpRequestBody::from($request));

		self::assertInstanceOf(Response::class, $result);
		self::assertSame(401, $result->getStatusCode());
		self::assertSame(
			'Bearer realm="MCP", error="login_required", resource_metadata="https://site.test/.well-known/oauth-protected-resource"',
			$result->getHeaderLine('WWW-Authenticate'),
		);
		self::assertSame(['error' => ['message' => 'Login required']], json_decode((string)$result->getBody(), true));
		self::assertFalse($this->personaContext->isResolved());
	}

	public function testAdminPersonaSkipsTheScopeGateAndIsStashed(): void
	{
		$this->mcpAuth->method('resolvePersona')->willReturn(McpPersona::ADMIN);
		$this->accessControl->expects($this->never())->method('authorityFor');
		$request = $this->request('{"method":"tools/call","params":{"name":"anything"}}');

		$result = $this->authorizer()->authorize($request, new Response(), McpRequestBody::from($request));

		self::assertSame(McpPersona::ADMIN, $result);
		self::assertSame(McpPersona::ADMIN, $this->personaContext->current());
		self::assertSame([], $this->personaContext->getScopes());
	}

	public function testBearerRequestCapturesScopesClientIdUserIdAndAuthority(): void
	{
		$this->mcpAuth->method('resolvePersona')->willReturn(McpPersona::AUTHENTICATED);
		$authority = new UserAuthority(isAdmin: false, groups: []);
		$this->accessControl->expects($this->once())->method('authorityFor')->willReturn($authority);
		$request = $this->request('{"method":"ping"}')
			->withAttribute('oauth_scopes', ['cms:read', new class {
				public function getIdentifier(): string
				{
					return 'mcp:tools';
				}
			}])
			->withAttribute('oauth_client_id', 'client-1')
			->withAttribute('oauth_user_id', 'joe@example.com');

		$result = $this->authorizer()->authorize($request, new Response(), McpRequestBody::from($request));

		self::assertSame(McpPersona::AUTHENTICATED, $result);
		self::assertSame(['cms:read', 'mcp:tools'], $this->personaContext->getScopes());
		self::assertSame('client-1', $this->personaContext->getClientId());
		self::assertSame('joe@example.com', $this->personaContext->getUserId());
		self::assertSame($authority, $this->personaContext->getAuthority());
	}

	public function testLifecycleMessagesBypassTheScopeGate(): void
	{
		$this->mcpAuth->method('resolvePersona')->willReturn(McpPersona::AUTHENTICATED);
		$request = $this->request('{"method":"notifications/initialized"}')->withAttribute('oauth_scopes', []);

		self::assertSame(McpPersona::AUTHENTICATED, $this->authorizer()->authorize($request, new Response(), McpRequestBody::from($request)));
	}

	public function testAScopeRejectionIsA403WithInsufficientScope(): void
	{
		$this->mcpAuth->method('resolvePersona')->willReturn(McpPersona::AUTHENTICATED);
		$request = $this->request('{"method":"tools/call","params":{"name":"create_object"}}')
			->withAttribute('oauth_scopes', ['cms:read'])
			->withAttribute('oauth_client_id', 'client-1');

		$result = $this->authorizer()->authorize($request, new Response(), McpRequestBody::from($request));

		self::assertInstanceOf(Response::class, $result);
		self::assertSame(403, $result->getStatusCode());
		self::assertSame(
			'Bearer realm="MCP", error="insufficient_scope", resource_metadata="https://site.test/.well-known/oauth-protected-resource"',
			$result->getHeaderLine('WWW-Authenticate'),
		);
		self::assertSame(
			['error' => ['message' => 'OAuth token scopes do not permit this MCP operation.', 'operation' => 'tools/call']],
			json_decode((string)$result->getBody(), true),
		);
	}

	public function testAnAllowedOperationPassesTheGate(): void
	{
		$this->mcpAuth->method('resolvePersona')->willReturn(McpPersona::AUTHENTICATED);
		$request = $this->request('{"method":"tools/list"}')->withAttribute('oauth_scopes', ['mcp:tools']);

		self::assertSame(McpPersona::AUTHENTICATED, $this->authorizer()->authorize($request, new Response(), McpRequestBody::from($request)));
	}
}
