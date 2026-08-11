<?php

declare(strict_types=1);

namespace TotalCMS\Action\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\OAuth\Adapter\LeagueScopeEntity;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

readonly class OAuthAuthorizeAction
{
	public function __construct(
		private AuthorizationServer $authServer,
		private PhpSession $session,
		private TwigRenderer $twig,
		private OAuthClientRepository $clients,
		private OAuthScopeRegistry $scopes,
		private CSRFTokenManager $csrf,
		private AccessControlService $accessControl,
		private UserValidationService $userValidation,
		private Config $config,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try {
			$authRequest = $this->authServer->validateAuthorizationRequest($request);
			$this->normalizeRequestedScopes($authRequest);
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

		$client = $this->clients->find($authRequest->getClient()->getIdentifier());

		// The list must show what approving will actually grant. finalizeScopes()
		// narrows admin-gated scopes away for users who can't convey them, so
		// displaying them here would ask the user to consent to a permission the
		// token will never carry. Mirrors LeagueScopeRepository::finalizeScopes()'s
		// widened gate: super admin OR SOME admin-domain access-group grant
		// (schemas, collectionsMeta, or a utils allow) — see
		// UserAuthority::hasAdminDomainGrants(). isAdmin() short-circuits first so
		// the common case never pays for authorityFor()'s group lookup.
		$collection = (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?: '');
		$ref        = OAuthUserRef::parse((string)$userId, $collection);
		$isAdmin    = $this->accessControl->isAdmin((string)$userId, $collection)
			|| $this->accessControl->authorityFor($ref)->hasAdminDomainGrants();
		$scopeRows = [];
		foreach ($authRequest->getScopes() as $scope) {
			$id = $scope->getIdentifier();
			if (!$isAdmin && in_array($id, OAuthScopeRegistry::ADMIN_GATED, true)) {
				continue;
			}
			$desc        = $this->scopes->has($id) ? $this->scopes->get($id)->description : $id;
			$scopeRows[] = ['identifier' => $id, 'description' => $desc];
		}

		return $this->twig->template($response, 'oauth/consent.twig', [
			'clientName' => $client instanceof \TotalCMS\Domain\OAuth\Data\OAuthClientData ? $client->name : $authRequest->getClient()->getIdentifier(),
			'clientIcon' => $client?->iconPath,
			'scopes'     => $scopeRows,
			'userId'     => (string)$userId,
			'userName'   => $this->displayName((string)$userId),
			'csrfField'  => $this->csrf->getTokenField(),
			'state'      => $authRequest->getState(),
		]);
	}

	/**
	 * Human-facing identity for the "signed in as" line. The session stores
	 * the user's object id, which can be as opaque as "3"; prefer their name,
	 * then email, before falling back to the raw id.
	 */
	/**
	 * Drop scope labels T3 doesn't define from the parsed request, and when
	 * nothing known remains substitute the oauth.defaultScope baseline.
	 *
	 * MCP clients request labels from their own vocabulary (claude.ai sends
	 * `scope=claudeai`). getScopeEntityByIdentifier() is tolerant so league
	 * doesn't reject the request outright; this is where the unknowns are
	 * actually removed — BEFORE the consent screen renders and BEFORE the
	 * request is serialized for the approve handler, so the user consents to
	 * exactly what the token will carry. Without the default substitution an
	 * unknown-only request would yield a scope-less token that MCP rejects
	 * with insufficient_scope on every call.
	 */
	private function normalizeRequestedScopes(\League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface $authRequest): void
	{
		$known = array_values(array_filter(
			$authRequest->getScopes(),
			fn (\League\OAuth2\Server\Entities\ScopeEntityInterface $s): bool => $this->scopes->has($s->getIdentifier()),
		));

		if ($known === []) {
			$default = (string)($this->config->oauth['defaultScope'] ?? 'cms:read mcp:tools mcp:resources mcp:prompts');
			$known   = array_map(
				static fn (string $id): LeagueScopeEntity => new LeagueScopeEntity($id),
				array_values(array_filter(explode(' ', $default), $this->scopes->has(...))),
			);
		}

		$authRequest->setScopes($known);
	}

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
