<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Middleware\Security\AuthorizationHeaderMiddleware;

function handlerCapturing(?ServerRequestInterface &$seen): RequestHandlerInterface
{
	return new class($seen) implements RequestHandlerInterface {
		/** @param ServerRequestInterface|null $seen */
		public function __construct(private mixed &$seen)
		{
		}

		public function handle(ServerRequestInterface $request): ResponseInterface
		{
			$this->seen = $request;

			return new Response(200);
		}
	};
}

test('restores Authorization from REDIRECT_HTTP_AUTHORIZATION', function (): void {
	$request = new ServerRequest('POST', '/mcp', [], null, '1.1', [
		'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc123',
	]);

	$seen = null;
	(new AuthorizationHeaderMiddleware())->process($request, handlerCapturing($seen));

	expect($seen->getHeaderLine('Authorization'))->toBe('Bearer abc123');
});

test('does not overwrite an existing Authorization header', function (): void {
	$request = (new ServerRequest('POST', '/mcp', [], null, '1.1', [
		'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer stale',
	]))->withHeader('Authorization', 'Bearer fresh');

	$seen = null;
	(new AuthorizationHeaderMiddleware())->process($request, handlerCapturing($seen));

	expect($seen->getHeaderLine('Authorization'))->toBe('Bearer fresh');
});

test('passes through untouched when neither source is present', function (): void {
	$request = new ServerRequest('GET', '/mcp');

	$seen = null;
	(new AuthorizationHeaderMiddleware())->process($request, handlerCapturing($seen));

	expect($seen->hasHeader('Authorization'))->toBeFalse();
});
