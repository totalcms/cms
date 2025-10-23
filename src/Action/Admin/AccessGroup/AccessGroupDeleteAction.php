<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\AccessGroup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Delete an access group.
 */
readonly class AccessGroupDeleteAction
{
	public function __construct(
		private AccessGroupManager $accessGroupManager,
		private JsonRenderer $jsonRenderer,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$id = $args['id'] ?? '';

		if ($id === '') {
			return $this->jsonRenderer->json($response->withStatus(400), [
				'error' => ['message' => 'ID is required'],
			]);
		}

		try {
			$deleted = $this->accessGroupManager->delete($id);

			if (!$deleted) {
				return $this->jsonRenderer->json($response->withStatus(404), [
					'error' => ['message' => 'Access group not found'],
				]);
			}

			return $this->jsonRenderer->json($response->withStatus(200), [
				'success' => true,
				'message' => 'Access group deleted successfully',
			]);
		} catch (\RuntimeException $e) {
			return $this->jsonRenderer->json($response->withStatus(400), [
				'error' => ['message' => $e->getMessage()],
			]);
		} catch (\Exception $e) {
			return $this->jsonRenderer->json($response->withStatus(500), [
				'error' => ['message' => $e->getMessage()],
			]);
		}
	}
}
