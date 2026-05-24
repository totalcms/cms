<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Renderer\JsonRenderer;

/**
 * DELETE /api/oauth-clients/{id} — delete a client and cascade-revoke its grants.
 */
readonly class OAuthClientDeleteAction
{
	public function __construct(
		private OAuthClientRepository $clients,
		private OAuthGrantRepository $grants,
		private JsonRenderer $jsonRenderer,
	) {
	}

	/** @param array<string,mixed> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$id = trim((string)($args['id'] ?? ''));

		if ($id === '') {
			return $this->jsonRenderer->json($response->withStatus(400), [
				'error' => ['message' => 'Client ID is required'],
			]);
		}

		if ($this->clients->find($id) === null) {
			return $this->jsonRenderer->json($response->withStatus(404), [
				'error' => ['message' => 'OAuth client not found'],
			]);
		}

		// Cascade: revoke all grants for this client, then delete the client.
		$this->grants->deleteByClientId($id);
		$this->clients->delete($id);

		return $this->jsonRenderer->json($response, [
			'success' => true,
			'message' => 'OAuth client deleted',
		]);
	}
}
