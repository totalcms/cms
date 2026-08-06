<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\OAuth;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthClientPruner;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Renderer\JsonRenderer;

/**
 * POST /api/oauth-clients/prune — remove stale self-registered clients.
 *
 * Delegates the staleness decision to OAuthClientPruner: dynamic clients
 * past the retention window with no active grant. Static clients and any
 * client a connector still holds a live grant for are untouched.
 */
readonly class OAuthClientPruneAction
{
	public function __construct(
		private OAuthClientPruner $pruner,
		private JsonRenderer $jsonRenderer,
		private PhpSession $session,
		private OAuthActivityLogger $activityLogger,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$removed = $this->pruner->pruneStaleDynamicClients();

		$deletedBy = (string)$this->session->get(SessionKeys::AUTH_USER, 'admin');
		foreach ($removed as $client) {
			$this->activityLogger->clientDeleted($client->id, $deletedBy);
		}

		return $this->jsonRenderer->json($response, [
			'success' => true,
			'removed' => count($removed),
			'message' => sprintf(
				'%d stale dynamic client%s removed',
				count($removed),
				count($removed) === 1 ? '' : 's',
			),
		]);
	}
}
