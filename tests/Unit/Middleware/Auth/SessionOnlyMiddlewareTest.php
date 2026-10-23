<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Middleware\Auth\SessionOnlyMiddleware;
use TotalCMS\Renderer\JsonRenderer;

function sessionOnlyRun(?string $authMethod, bool &$reached): ResponseInterface
{
	$factory = new Psr17Factory();
	$request = $factory->createServerRequest('POST', '/api/extensions/install');
	if ($authMethod !== null) {
		$request = $request->withAttribute('authMethod', $authMethod);
	}
	$handler = new class($factory, $reached) implements RequestHandlerInterface {
		public function __construct(private Psr17Factory $factory, private bool &$reached)
		{
		}

		public function handle(ServerRequestInterface $request): ResponseInterface
		{
			$this->reached = true;

			return $this->factory->createResponse(204);
		}
	};

	return (new SessionOnlyMiddleware($factory, new JsonRenderer()))->process($request, $handler);
}

describe('SessionOnlyMiddleware', function (): void {
	test('rejects API key callers with 403', function (): void {
		$reached  = false;
		$response = sessionOnlyRun('apikey', $reached);

		expect($response->getStatusCode())->toBe(403)
			->and($reached)->toBeFalse()
			->and((string)$response->getBody())->toContain('browser sessions only');
	});

	test('rejects OAuth bearer callers with 403', function (): void {
		$reached = false;

		expect(sessionOnlyRun('oauth_bearer', $reached)->getStatusCode())->toBe(403)
			->and($reached)->toBeFalse();
	});

	test('passes session and unset auth methods through', function (?string $method): void {
		$reached = false;

		expect(sessionOnlyRun($method, $reached)->getStatusCode())->toBe(204)
			->and($reached)->toBeTrue();
	})->with([null, 'session']);
});
