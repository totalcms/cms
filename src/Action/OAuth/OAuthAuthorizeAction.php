<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Renderer\TwigRenderer;

readonly class OAuthAuthorizeAction
{
	public function __construct(
		private AuthorizationServer $authServer,
		private PhpSession $session,
		private TwigRenderer $twig,
		private OAuthClientRepository $clients,
		private OAuthScopeRegistry $scopes,
		private CSRFTokenManager $csrf,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try {
			$authRequest = $this->authServer->validateAuthorizationRequest($request);
		} catch (OAuthServerException $e) {
			// Do NOT redirect on validation errors — the redirect_uri may be attacker-controlled.
			return $this->twig->template(
				$response->withStatus($e->getHttpStatusCode()),
				'oauth/error.twig',
				[
					'errorType'        => $e->getErrorType(),
					'errorDescription' => $e->getMessage(),
				],
			);
		}

		$userId = $this->session->get(SessionKeys::AUTH_USER);
		if ($userId === null) {
			// Return-to via the session, the same mechanism DualAuthMiddleware
			// uses for admin pages: AuthLoginSubmitAction reads
			// REQUEST_ORIGIN_URL after authentication and sends the operator
			// back here — full authorize query string intact — so the consent
			// screen appears instead of the dashboard. (A `next` query param
			// was passed previously, but nothing ever consumed it.)
			$this->session->set(SessionKeys::REQUEST_ORIGIN_URL, (string)$request->getUri());
			$loginUrl = RouteContext::fromRequest($request)->getRouteParser()->urlFor('login');

			return $response
				->withStatus(302)
				->withHeader('Location', $loginUrl);
		}

		// Persist the parsed AuthorizationRequest for the POST handler.
		// league/oauth2-server's AuthorizationRequest is serialize-safe.
		$this->session->set('oauth_authorize_request', serialize($authRequest));

		$client    = $this->clients->find($authRequest->getClient()->getIdentifier());
		$scopeRows = [];
		foreach ($authRequest->getScopes() as $scope) {
			$id          = $scope->getIdentifier();
			$desc        = $this->scopes->has($id) ? $this->scopes->get($id)->description : $id;
			$scopeRows[] = ['identifier' => $id, 'description' => $desc];
		}

		return $this->twig->template($response, 'oauth/consent.twig', [
			'clientName' => $client instanceof \TotalCMS\Domain\OAuth\Data\OAuthClientData ? $client->name : $authRequest->getClient()->getIdentifier(),
			'clientIcon' => $client?->iconPath,
			'scopes'     => $scopeRows,
			'userId'     => (string)$userId,
			'csrfField'  => $this->csrf->getTokenField(),
			'state'      => $authRequest->getState(),
		]);
	}
}
