<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

readonly class OAuthTokenAction
{
	public function __construct(
		private AuthorizationServer $authServer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try {
			return $this->authServer->respondToAccessTokenRequest($request, $response);
		} catch (OAuthServerException $e) {
			return $e->generateHttpResponse($response);
		}
	}
}
