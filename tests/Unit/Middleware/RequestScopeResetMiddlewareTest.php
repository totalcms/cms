<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Middleware\RequestScopeResetMiddleware;
use TotalCMS\Support\Config;

/**
 * A long-lived worker serves many requests from one process, so the actor
 * and the guard's memoised rules from one request must be gone before the
 * next starts.
 */
final class RequestScopeResetMiddlewareTest extends TestCase
{
	private Config $config;

	protected function setUp(): void
	{
		$this->config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->auth = ['enable' => true, 'collection' => 'auth'];
	}

	public function testDropsThePreviousRequestsActor(): void
	{
		$actors = new ActorContext();
		$actors->set(Actor::system());

		$seen    = null;
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturnCallback(function () use ($actors, &$seen): ResponseInterface {
			$seen = $actors->current()->kind;

			return $this->createMock(ResponseInterface::class);
		});

		$this->middleware($actors, $this->sessionFor(null))->process($this->createMock(ServerRequestInterface::class), $handler);

		$this->assertSame(ActorKind::Anonymous, $seen);
	}

	public function testTheSessionUserBecomesTheActorWhenFirstRead(): void
	{
		$actors = new ActorContext();

		$accessControl = $this->createMock(AccessControlService::class);
		$accessControl->expects($this->once())->method('authorityOf')->with('alex', 'auth')->willReturn(new UserAuthority(isAdmin: false, groups: []));

		$seen    = null;
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturnCallback(function () use ($actors, &$seen): ResponseInterface {
			$seen = $actors->current();

			return $this->createMock(ResponseInterface::class);
		});

		$this->middleware($actors, $this->sessionFor('alex'), $accessControl)->process($this->createMock(ServerRequestInterface::class), $handler);

		$this->assertSame(ActorKind::Member, $seen?->kind);
		$this->assertSame('alex', $seen?->userId);
	}

	public function testForgetsTheMemoisedGuardRules(): void
	{
		// The memo makes the second read free, the reset makes the third a fetch.
		$schema             = new SchemaData();
		$schema->id         = 'notes';
		$schema->properties = ['title' => []];

		$fetcher = $this->createMock(SchemaFetcher::class);
		$fetcher->expects($this->exactly(2))->method('fetchSchemaForCollection')->willReturn($schema);

		$guard = $this->guard($fetcher);
		$guard->protectedFieldsFor('notes');
		$guard->protectedFieldsFor('notes');

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($this->createMock(ResponseInterface::class));

		$this->middleware(new ActorContext(), $this->sessionFor(null), null, $guard)->process($this->createMock(ServerRequestInterface::class), $handler);

		$guard->protectedFieldsFor('notes');
	}

	private function middleware(ActorContext $actors, SessionInterface $session, ?AccessControlService $accessControl = null, ?AccessGuard $guard = null): RequestScopeResetMiddleware
	{
		return new RequestScopeResetMiddleware(
			$actors,
			new ActorResolver($session, $accessControl ?? $this->createMock(AccessControlService::class), $this->config),
			$guard ?? $this->guard($this->createMock(SchemaFetcher::class)),
		);
	}

	private function sessionFor(?string $userId): SessionInterface
	{
		$session = $this->createMock(SessionInterface::class);
		$session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === SessionKeys::AUTH_USER ? $userId : null);

		return $session;
	}

	private function guard(SchemaFetcher $schemaFetcher): AccessGuard
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn($this->createMock(LoggerInterface::class));

		return new AccessGuard(
			$schemaFetcher,
			$this->createMock(ObjectFetcher::class),
			$this->createMock(OwnerPropertyResolver::class),
			$this->config,
			$loggerFactory,
		);
	}
}
