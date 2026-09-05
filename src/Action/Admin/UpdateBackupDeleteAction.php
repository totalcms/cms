<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Update\Service\UpdateApplier;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Removes the copy of the previous version a successful update kept, so an
 * operator can reclaim the disk without shell access.
 *
 * Deliberately only DELETES. Restoring that copy swaps the running application
 * and is a materially more dangerous operation than freeing a directory the
 * live site does not read — that stays with `tcms update:rollback`, where it
 * has a confirmation prompt and a terminal in front of it.
 */
readonly class UpdateBackupDeleteAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private UpdateApplier $updateApplier,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try {
			$this->updateApplier->discardRetainedBackup();

			return $this->renderer->json($response, ['success' => true]);
		} catch (\Throwable $e) {
			return $this->renderer->json($response, [
				'success' => false,
				'error'   => 'Could not remove the previous version: ' . $e->getMessage(),
			])->withStatus(500);
		}
	}
}
