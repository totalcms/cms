<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Auth\Service\ForcedValueContext;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Middleware\RequestScopeResetMiddleware;
use TotalCMS\Support\Config;

/**
 * A long-lived worker serves many requests from one process, so forced
 * values remembered in one request must be gone before the next starts.
 */
final class RequestScopeResetMiddlewareTest extends TestCase
{
	public function testClearsTheContextBeforeTheRequestIsHandled(): void
	{
		$context = new ForcedValueContext();
		$context->remember('showcase', 'site-1', ['draft' => true]);

		$request  = $this->createMock(ServerRequestInterface::class);
		$response = $this->createMock(ResponseInterface::class);

		$seenDuringHandle = null;
		$handler          = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->with($this->identicalTo($request))
			->willReturnCallback(function () use ($context, $response, &$seenDuringHandle): ResponseInterface {
				$seenDuringHandle = $context->forcedFor('showcase', 'site-1');

				return $response;
			});

		$result = (new RequestScopeResetMiddleware($context, $this->makePolicy($this->createMock(SchemaFetcher::class))))->process($request, $handler);

		$this->assertSame($response, $result);
		$this->assertSame([], $seenDuringHandle);
		$this->assertSame([], $context->forcedFor('showcase', 'site-1'));
	}

	public function testLeavesValuesRememberedDuringTheRequestForItsOwnSaves(): void
	{
		// Clearing happens on the way in only: the request's own entries must
		// stay readable for every save it makes.
		$context = new ForcedValueContext();

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturnCallback(function () use ($context): ResponseInterface {
			$context->remember('showcase', 'site-1', ['draft' => true]);

			return $this->createMock(ResponseInterface::class);
		});

		(new RequestScopeResetMiddleware($context, $this->makePolicy($this->createMock(SchemaFetcher::class))))->process($this->createMock(ServerRequestInterface::class), $handler);

		$this->assertSame(['draft' => true], $context->forcedFor('showcase', 'site-1'));
	}

	public function testForgetsTheMemoisedFieldPolicyRules(): void
	{
		// AuthFieldPolicy is final, so use the real one and count schema fetches:
		// the memo makes the second read free, the reset makes the third a fetch.
		$schema             = new SchemaData();
		$schema->id         = 'notes';
		$schema->properties = ['title' => []];

		$fetcher = $this->createMock(SchemaFetcher::class);
		$fetcher->expects($this->exactly(2))->method('fetchSchemaForCollection')->willReturn($schema);

		$policy = $this->makePolicy($fetcher);
		$policy->protectedFieldsFor('notes');
		$policy->protectedFieldsFor('notes');

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($this->createMock(ResponseInterface::class));

		(new RequestScopeResetMiddleware(new ForcedValueContext(), $policy))->process($this->createMock(ServerRequestInterface::class), $handler);

		$policy->protectedFieldsFor('notes');
	}

	private function makePolicy(SchemaFetcher $schemaFetcher): AuthFieldPolicy
	{
		$config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth = ['enable' => true, 'collection' => 'auth'];

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn($this->createMock(LoggerInterface::class));

		return new AuthFieldPolicy(
			$schemaFetcher,
			$this->createMock(UserValidationService::class),
			$this->createMock(ObjectFetcher::class),
			$this->createMock(OwnerPropertyResolver::class),
			$config,
			$loggerFactory,
		);
	}
}
