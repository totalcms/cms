<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * HTMX action to enable or disable an extension.
 */
readonly class ExtensionToggleAction
{
	public function __construct(
		private ExtensionManager $manager,
		private ExtensionStateRepository $stateRepository,
		private JsonRenderer $renderer,
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
		$extensionId = $args['extension'] ?? '';
		$action      = $args['action'] ?? '';

		if ($extensionId === '') {
			return $this->renderer->json($response, ['error' => 'Extension ID required'])->withStatus(400);
		}

		if ($action === 'enable') {
			$this->manager->enable($extensionId);
		} elseif ($action === 'disable') {
			$this->manager->disable($extensionId);
		} else {
			return $this->renderer->json($response, ['error' => 'Invalid action'])->withStatus(400);
		}

		$state = $this->stateRepository->getState($extensionId);

		return $this->renderer->json($response, [
			'id'      => $extensionId,
			'enabled' => $state instanceof \TotalCMS\Domain\Extension\Data\ExtensionState && $state->enabled,
			'message' => $action === 'enable' ? 'Extension enabled' : 'Extension disabled',
		]);
	}
}
