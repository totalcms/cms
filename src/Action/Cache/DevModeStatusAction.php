<?php

declare(strict_types=1);

namespace TotalCMS\Action\Cache;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Get development mode status
 */
final class DevModeStatusAction
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
		$status = $this->devModeManager->getDevModeStatus();

		return $this->jsonRenderer->json($response, [
			'success' => true,
			'devmode' => $status
		]);
	}
}