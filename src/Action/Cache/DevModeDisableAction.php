<?php

declare(strict_types = 1);

namespace TotalCMS\Action\Cache;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Disable development mode.
 */
readonly class DevModeDisableAction
{
	public function __construct(
		private DevModeManager $devModeManager,
		private JsonRenderer $jsonRenderer,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$this->devModeManager->disableDevMode();
		$status = $this->devModeManager->getDevModeStatus();

		return $this->jsonRenderer->json($response, [
			'success' => true,
			'message' => 'Development mode disabled. Caching has been restored.',
			'devmode' => $status,
		]);
	}
}
