<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Update\Service\UpdateApplier;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\Domain\Update\Service\UpdateDownloader;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Handles the one-click update process from the admin dashboard.
 */
readonly class UpdateAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private UpdateChecker $updateChecker,
		private UpdateDownloader $updateDownloader,
		private UpdateApplier $updateApplier,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try {
			// Check for available update
			$updateInfo = $this->updateChecker->checkForUpdate(forceRefresh: true);

			if (!$updateInfo->available) {
				return $this->renderer->json($response, [
					'success' => true,
					'message' => 'Already up to date.',
				]);
			}

			// Download the update
			$zipPath = $this->updateDownloader->download($updateInfo->version, $updateInfo->downloadUrl);

			// Apply the update
			$this->updateApplier->apply($zipPath, $updateInfo->version);

			// Clear the update check cache
			$this->updateChecker->clearCache();

			return $this->renderer->json($response, [
				'success' => true,
				'message' => "Updated to {$updateInfo->version} successfully. Reloading...",
				'version' => $updateInfo->version,
			]);
		} catch (\Throwable $e) {
			return $this->renderer->json($response, [
				'success' => false,
				'error'   => 'Update failed: ' . $e->getMessage(),
			])->withStatus(500);
		}
	}
}
