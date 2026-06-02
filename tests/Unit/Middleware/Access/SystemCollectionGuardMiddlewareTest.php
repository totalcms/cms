<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Access;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Middleware\Access\SystemCollectionGuardMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/** Overrides route-argument resolution so the gate logic can be tested directly. */
final readonly class TestableSystemCollectionGuardMiddleware extends SystemCollectionGuardMiddleware
{
	public function __construct(
		SessionInterface $session,
		UserValidationService $userValidation,
		JsonRenderer $jsonRenderer,
		ResponseFactoryInterface $responseFactory,
		Config $config,
		private string $collection,
	) {
		parent::__construct($session, $userValidation, $jsonRenderer, $responseFactory, $config);
	}

	protected function resolveCollection(ServerRequestInterface $request): string
	{
		return $this->collection;
	}
}

describe('SystemCollectionGuardMiddleware', function (): void {
	beforeEach(function (): void {
		$this->session         = $this->createMock(SessionInterface::class);
		$this->userValidation  = $this->createMock(UserValidationService::class);
		$this->jsonRenderer    = $this->createMock(JsonRenderer::class);
		$this->responseFactory = $this->createMock(ResponseFactoryInterface::class);

		$this->config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->auth = ['enable' => true];

		$this->handler     = $this->createMock(RequestHandlerInterface::class);
		$this->passthrough = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($this->passthrough);

		$this->forbidden = $this->createMock(ResponseInterface::class);
		$this->forbidden->method('withStatus')->willReturnSelf();
		$this->responseFactory->method('createResponse')->willReturn($this->forbidden);
		$this->jsonRenderer->method('json')->willReturn($this->forbidden);

		$this->request = function (string $method): ServerRequestInterface {
			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('getMethod')->willReturn($method);

			return $request;
		};

		$this->make = function (string $collection, bool $superAdmin, string $userId = 'u1'): TestableSystemCollectionGuardMiddleware {
			$this->session->method('get')->willReturnCallback(
				static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? $userId : null,
			);
			$this->userValidation->method('isSuperAdmin')->willReturn($superAdmin);

			return new TestableSystemCollectionGuardMiddleware(
				$this->session,
				$this->userValidation,
				$this->jsonRenderer,
				$this->responseFactory,
				$this->config,
				$collection,
			);
		};
	});

	it('blocks a non-super-admin writing a system collection', function (): void {
		$middleware = ($this->make)('automations', superAdmin: false);
		$result     = $middleware->process(($this->request)('POST'), $this->handler);
		expect($result)->toBe($this->forbidden);
	});

	it('allows a super-admin writing a system collection', function (): void {
		$middleware = ($this->make)('automations', superAdmin: true);
		$result     = $middleware->process(($this->request)('POST'), $this->handler);
		expect($result)->toBe($this->passthrough);
	});

	it('allows writes to non-system collections', function (): void {
		$middleware = ($this->make)('blog', superAdmin: false);
		$result     = $middleware->process(($this->request)('PUT'), $this->handler);
		expect($result)->toBe($this->passthrough);
	});

	it('does not gate reads of system collections', function (): void {
		$middleware = ($this->make)('automations', superAdmin: false);
		$result     = $middleware->process(($this->request)('GET'), $this->handler);
		expect($result)->toBe($this->passthrough);
	});

	it('blocks an api-key write (no super-admin session) to a system collection', function (): void {
		$middleware = ($this->make)('automations', superAdmin: false, userId: '');
		$result     = $middleware->process(($this->request)('POST'), $this->handler);
		expect($result)->toBe($this->forbidden);
	});

	it('passes through when auth is disabled', function (): void {
		$this->config->auth = ['enable' => false];
		$middleware         = ($this->make)('automations', superAdmin: false);
		$result             = $middleware->process(($this->request)('POST'), $this->handler);
		expect($result)->toBe($this->passthrough);
	});
});
