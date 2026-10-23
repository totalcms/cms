<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\OAuth\Adapter\LeagueScopeRepository;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Service\OAuthLoginCollection;
use TotalCMS\Domain\OAuth\Service\OAuthRedirectClassifier;
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
		private LeagueScopeRepository $scopeRepository,
		private UserValidationService $userValidation,
		private OAuthRedirectClassifier $redirectClassifier,
		private OAuthLoginCollection $loginCollection,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try {
			$authRequest = $this->authServer->validateAuthorizationRequest($request);
			$this->scopeRepository->normalizeRequested($authRequest);
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
		// A session from an auth collection that may not authorize (see
		// OAuthLoginCollection) is treated as logged out.
		if ($userId === null || !$this->loginCollection->accepts(
			(string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?: ''),
			(string)$userId,
			$authRequest->getClient()->getIdentifier(),
		)) {
			return $this->toLogin($request, $response);
		}

		// Persist the parsed AuthorizationRequest for the POST handler.
		// league/oauth2-server's AuthorizationRequest is serialize-safe.
		$this->session->set('oauth_authorize_request', serialize($authRequest));

		$client = $this->clients->find($authRequest->getClient()->getIdentifier());

		$scopeRows = $this->consentScopes($authRequest, (string)$userId);

		// Where approval sends the code. league leaves getRedirectUri() null
		// when the request omitted redirect_uri and the client's registered
		// one is used instead — show that one.
		$redirectUri = $authRequest->getRedirectUri();
		if ($redirectUri === null) {
			$registered  = $authRequest->getClient()->getRedirectUri();
			$redirectUri = is_array($registered) ? (string)($registered[0] ?? '') : $registered;
		}
		$redirect = $this->redirectClassifier->classify($redirectUri);

		return $this->twig->template($response, 'oauth/consent.twig', [
			'clientName'     => $client instanceof OAuthClientData ? $client->name : $authRequest->getClient()->getIdentifier(),
			'clientIcon'     => $client?->iconPath,
			'selfRegistered' => $client instanceof OAuthClientData && $client->isDynamic,
			'redirectHost'   => $redirect['host'],
			'redirectKind'   => $redirect['kind'],
			'scopes'         => $scopeRows,
			'userId'         => (string)$userId,
			'userName'       => $this->displayName((string)$userId),
			'csrfField'      => $this->csrf->getTokenField(),
			'state'          => $authRequest->getState(),
		]);
	}

	/**
	 * The scopes the consent screen lists: what approving will actually grant.
	 * finalizeScopes() narrows admin-gated scopes away for users who can't
	 * convey them, so displaying them here would ask the user to consent to a
	 * permission the token will never carry. Both ask the scope repository
	 * the same question.
	 *
	 * @return list<array{identifier: string, description: string}>
	 */
	private function consentScopes(AuthorizationRequestInterface $authRequest, string $userId): array
	{
		$collection = (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?: '');
		$isAdmin    = $this->scopeRepository->userCanConveyAdmin($collection !== '' ? OAuthUserRef::compose($collection, $userId) : $userId);

		$rows = [];
		foreach ($authRequest->getScopes() as $scope) {
			$id = $scope->getIdentifier();
			if (!$isAdmin && in_array($id, OAuthScopeRegistry::ADMIN_GATED, true)) {
				continue;
			}
			$rows[] = ['identifier' => $id, 'description' => $this->scopes->has($id) ? $this->scopes->get($id)->description : $id];
		}

		return $rows;
	}

	/**
	 * Send the caller to sign in, then back here.
	 *
	 * Return-to via the session, the same mechanism DualAuthMiddleware uses
	 * for admin pages: AuthLoginSubmitAction reads REQUEST_ORIGIN_URL after
	 * authentication and sends the operator back here — full authorize query
	 * string intact — so the consent screen appears instead of the dashboard.
	 * (A `next` query param was passed previously, but nothing ever consumed
	 * it.) The login page is the OAuth login collection's, when one is set.
	 */
	private function toLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$this->session->set(SessionKeys::REQUEST_ORIGIN_URL, (string)$request->getUri());
		$configured = $this->loginCollection->configured();
		$loginUrl   = RouteContext::fromRequest($request)->getRouteParser()->urlFor('login', $configured !== '' ? ['collection' => $configured] : []);

		return $response->withStatus(302)->withHeader('Location', $loginUrl);
	}

	/**
	 * Human-facing identity for the "signed in as" line. The session stores
	 * the user's object id, which can be as opaque as "3"; prefer their name,
	 * then email, before falling back to the raw id.
	 */
	private function displayName(string $userId): string
	{
		$collection = (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?: '');

		try {
			$user = $this->userValidation->validateUserById($userId, $collection);
		} catch (\Throwable) {
			return $userId;
		}

		$name  = trim((string)($user['name'] ?? ''));
		$email = trim((string)($user['email'] ?? ''));

		return $name !== '' ? $name : ($email !== '' ? $email : $userId);
	}
}
