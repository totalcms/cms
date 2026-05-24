<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Renderer\JsonRenderer;

/**
 * DELETE /api/oauth-grants/{id} — revoke a single OAuth grant.
 */
readonly class OAuthGrantRevokeAction
{
	public function __construct(
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
				'error' => ['message' => 'Grant ID is required'],
			]);
		}

		if ($this->grants->find($id) === null) {
			return $this->jsonRenderer->json($response->withStatus(404), [
				'error' => ['message' => 'Grant not found'],
			]);
		}

		$this->grants->delete($id);

		return $this->jsonRenderer->json($response, [
			'success' => true,
			'message' => 'Grant revoked',
		]);
	}
}
