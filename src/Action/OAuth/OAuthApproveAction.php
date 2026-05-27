<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\OAuth\Adapter\LeagueClientEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueScopeEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueUserEntity;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Renderer\TwigRenderer;

readonly class OAuthApproveAction
{
	public function __construct(
		private AuthorizationServer $authServer,
		private PhpSession $session,
		private TwigRenderer $twig,
		private OAuthActivityLogger $activityLogger,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$stashed = $this->session->get('oauth_authorize_request');
		if (!is_string($stashed) || $stashed === '') {
			return $this->twig->template(
				$response->withStatus(400),
				'oauth/error.twig',
				['errorType' => 'invalid_request', 'errorDescription' => 'No pending authorization request.'],
			);
		}

		$authRequest = unserialize($stashed, ['allowed_classes' => [
			AuthorizationRequest::class,
			LeagueClientEntity::class,
			LeagueScopeEntity::class,
			LeagueUserEntity::class,
		]]);
		if (!$authRequest instanceof AuthorizationRequest) {
			return $this->twig->template(
				$response->withStatus(400),
				'oauth/error.twig',
				['errorType' => 'invalid_request', 'errorDescription' => 'Authorization request is malformed.'],
			);
		}

		$userId = $this->session->get(SessionKeys::AUTH_USER);
		if ($userId === null) {
			return $this->twig->template(
				$response->withStatus(401),
				'oauth/error.twig',
				['errorType' => 'access_denied', 'errorDescription' => 'You must be logged in.'],
			);
		}

		$parsed   = (array) $request->getParsedBody();
		$decision = (string) ($parsed['decision'] ?? 'deny');

		$clientId = $authRequest->getClient()->getIdentifier();
		$userIdStr = (string) $userId;

		$authRequest->setUser(new LeagueUserEntity($userIdStr));
		$authRequest->setAuthorizationApproved($decision === 'approve');

		// Clear the stashed request before delegating — avoid replay on server error.
		$this->session->delete('oauth_authorize_request');

		if ($decision !== 'approve') {
			$this->activityLogger->consentDenied($clientId, $userIdStr);

			try {
				return $this->authServer->completeAuthorizationRequest($authRequest, $response);
			} catch (OAuthServerException $e) {
				return $this->twig->template(
					$response->withStatus($e->getHttpStatusCode()),
					'oauth/error.twig',
					['errorType' => $e->getErrorType(), 'errorDescription' => $e->getMessage()],
				);
			}
		}

		try {
			$result = $this->authServer->completeAuthorizationRequest($authRequest, $response);
		} catch (OAuthServerException $e) {
			return $this->twig->template(
				$response->withStatus($e->getHttpStatusCode()),
				'oauth/error.twig',
				['errorType' => $e->getErrorType(), 'errorDescription' => $e->getMessage()],
			);
		}

		$scopeObjects = $authRequest->getScopes();
		$scopes = array_map(
			static fn (\League\OAuth2\Server\Entities\ScopeEntityInterface $s): string => $s->getIdentifier(),
			$scopeObjects,
		);
		$this->activityLogger->consentGranted($clientId, $userIdStr, array_values($scopes));

		return $result;
	}
}
