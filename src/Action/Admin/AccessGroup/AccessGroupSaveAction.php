<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\AccessGroup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Save (create or update) an access group.
 */
readonly class AccessGroupSaveAction
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
		array $args = [],
	): ResponseInterface {
		$data = (array)$request->getParsedBody();

		// Get ID from route parameter or POST data
		$id          = trim((string)($args['id'] ?? $data['id'] ?? ''));
		$description = trim((string)($data['description'] ?? ''));

		if ($id === '') {
			return $this->jsonRenderer->json($response->withStatus(400), [
				'error' => ['message' => 'ID is required'],
			]);
		}

		// Parse global methods
		$methods = $data['methods'] ?? [];
		if (!is_array($methods)) {
			$methods = [];
		}

		// Build permissions structure
		$permissions = $this->buildPermissions($data);

		try {
			$group = new AccessGroupData([
				'id'          => $id,
				'description' => $description,
				'methods'     => $methods,
				'permissions' => $permissions,
			]);

			$this->accessGroupManager->save($group);

			return $this->jsonRenderer->json($response->withStatus(200), [
				'success' => true,
				'message' => 'Access group saved successfully',
				'group'   => $group->toArray(),
			]);
		} catch (\Exception $e) {
			return $this->jsonRenderer->json($response->withStatus(500), [
				'error' => ['message' => $e->getMessage()],
			]);
		}
	}

	/**
	 * Build permissions structure from form data.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function buildPermissions(array $data): array
	{
		return [
			'collections' => [
				'methods' => $data['collections-methods'] ?? [],
				'all'     => isset($data['collections-all']) && in_array('all', (array)$data['collections-all']),
				'allowed' => $data['collections-allowed'] ?? [],
			],
			'schemas' => [
				'methods' => $data['schemas-methods'] ?? [],
				'all'     => isset($data['schemas-all']) && in_array('all', (array)$data['schemas-all']),
				'allowed' => $data['schemas-allowed'] ?? [],
			],
			'templates'  => isset($data['templates']) && in_array('templates', (array)$data['templates']),
			'mailer'     => isset($data['mailer']) && in_array('mailer', (array)$data['mailer']),
			'playground' => isset($data['playground']) && in_array('playground', (array)$data['playground']),
			'docs'       => isset($data['docs']) && in_array('docs', (array)$data['docs']),
			'utils'      => [
				'all'     => isset($data['utils-all']) && in_array('all', (array)$data['utils-all']),
				'allowed' => $data['utils-allowed'] ?? [],
			],
			'settings' => [
				'all'     => isset($data['settings-all']) && in_array('all', (array)$data['settings-all']),
				'allowed' => $data['settings-allowed'] ?? [],
			],
		];
	}
}
