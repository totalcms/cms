<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\OAuth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\OAuth\Service\OAuthClientCreator;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Renderer\JsonRenderer;

/**
 * POST /api/oauth-clients — create a static OAuth client.
 * Returns JSON with the plaintext secret (shown once).
 */
readonly class OAuthClientCreateAction
{
	public function __construct(
		private OAuthClientCreator $creator,
		private PhpSession $session,
		private JsonRenderer $jsonRenderer,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$data = (array)$request->getParsedBody();

		$name         = trim((string)($data['name'] ?? ''));
		$redirectsRaw = (string)($data['redirect_uris'] ?? '');
		$scopes       = isset($data['scopes']) && is_array($data['scopes'])
			? array_values(array_map('strval', $data['scopes']))
			: [];

		if ($name === '') {
			return $this->jsonRenderer->json($response->withStatus(400), [
				'error' => ['message' => 'Name is required'],
			]);
		}

		$redirectUris = array_values(array_filter(
			array_map('trim', preg_split('/\s+/', $redirectsRaw) ?: []),
			static fn (string $s): bool => $s !== '',
		));

		if ($redirectUris === []) {
			return $this->jsonRenderer->json($response->withStatus(400), [
				'error' => ['message' => 'At least one redirect URI is required'],
			]);
		}

		$createdBy = (string)$this->session->get(SessionKeys::AUTH_USER, 'admin');

		try {
			$result = $this->creator->create(
				name: $name,
				redirectUris: $redirectUris,
				allowedScopes: $scopes,
				createdBy: $createdBy,
				isDynamic: false,
				isConfidential: true,
			);
		} catch (\InvalidArgumentException $e) {
			return $this->jsonRenderer->json($response->withStatus(400), [
				'error' => ['message' => $e->getMessage()],
			]);
		}

		return $this->jsonRenderer->json($response->withStatus(201), [
			'success' => true,
			'client'  => [
				'id'            => $result['client']->id,
				'name'          => $result['client']->name,
				'secret'        => $result['secret'],
				'redirect_uris' => $result['client']->redirectUris,
				'scopes'        => $result['client']->scopes,
				'created_at'    => $result['client']->createdAt,
			],
		]);
	}
}
