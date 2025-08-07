<?php

declare(strict_types=1);

namespace TotalCMS\Action\Cache;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Enable development mode for 3 hours
 */
final class DevModeEnableAction
{
	public function __construct(
		private readonly DevModeManager $devModeManager,
		private readonly JsonRenderer $jsonRenderer
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response
	): ResponseInterface {
		$this->devModeManager->enableDevMode();
		$status = $this->devModeManager->getDevModeStatus();

		return $this->jsonRenderer->json($response, [
			'success' => true,
			'message' => 'Development mode enabled for 3 hours. Caching is now disabled.',
			'devmode' => $status
		]);
	}
}